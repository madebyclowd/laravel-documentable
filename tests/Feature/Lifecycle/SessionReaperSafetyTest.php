<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\UploadSessionLifecycle;
use MadeByClowd\Documentable\Tests\TestCase;

class SessionReaperSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUpDisk(string $disk = 's3_reaper_disk', array $overrides = []): void
    {
        config()->set("filesystems.disks.{$disk}", array_merge([
            'driver' => 's3',
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'key',
            'secret' => 'secret',
            'request_checksum_calculation' => 'when_required',
        ], $overrides));
    }

    protected function makeExpiredSession(string $disk = 's3_reaper_disk', string $path = 'uploads/file.bin'): UploadSession
    {
        $type = DocumentType::create([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => $disk,
            'path_prefix' => 'uploads',
        ]);

        return UploadSession::create([
            'document_type_id' => $type->id,
            'disk' => $disk,
            'kind' => UploadKind::Single,
            'client_filename' => 'file.bin',
            'path' => $path,
            'status' => UploadSessionStatus::Initiated,
            'expires_at' => now()->subHours(2),
        ]);
    }

    public function test_session_reaper_deletes_object_on_s3_and_marks_expired(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_reaper_disk', ['handler' => $mock]);

        $session = $this->makeExpiredSession('s3_reaper_disk', 'uploads/file.bin');

        // HeadObject returns 200
        $mock->append(new Result(['ContentLength' => 100]));
        // DeleteObject returns 204
        $mock->append(new Result);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Expired, $session->fresh()->status);
    }

    public function test_session_reaper_treats_404_as_already_gone_and_marks_expired(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_reaper_disk', ['handler' => $mock]);

        $session = $this->makeExpiredSession('s3_reaper_disk', 'uploads/missing.bin');

        // HeadObject returns 404 NotFound
        $cmd = new Command('HeadObject', ['Bucket' => 'test-bucket', 'Key' => 'uploads/missing.bin']);
        $mock->append(new S3Exception('Not Found', $cmd, ['response' => new Response(404), 'code' => 'NotFound']));

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Expired, $session->fresh()->status);
    }

    public function test_session_reaper_delete_failure_leaves_row_open_and_exits_non_zero(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_reaper_disk', ['handler' => $mock]);

        $session = $this->makeExpiredSession('s3_reaper_disk', 'uploads/error.bin');

        // HeadObject returns 200
        $mock->append(new Result(['ContentLength' => 100]));
        // DeleteObject fails
        $cmd = new Command('DeleteObject', ['Bucket' => 'test-bucket', 'Key' => 'uploads/error.bin']);
        $mock->append(new S3Exception('InternalError', $cmd, ['code' => 'InternalError']));

        Log::shouldReceive('error')->once();

        $this->artisan('documents:clean-orphaned')->assertExitCode(1);

        // Row remains initiated (NOT expired) because transaction rolled back
        $fresh = $session->fresh();
        $this->assertSame(UploadSessionStatus::Initiated, $fresh->status);
    }

    public function test_session_reaper_concurrent_attachment_safety(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_reaper_disk', ['handler' => $mock]);

        $session = $this->makeExpiredSession('s3_reaper_disk', 'uploads/attached.bin');

        // Simulate concurrent attachment: row has storage_file_id set
        $storageFile = StorageFile::create([
            'disk' => 's3_reaper_disk',
            'path' => 'uploads/attached.bin',
            'file_hash' => 'hash123',
            'mime_type' => 'application/octet-stream',
            'size_bytes' => 100,
        ]);
        $session->update([
            'storage_file_id' => $storageFile->id,
            'status' => UploadSessionStatus::Attached,
        ]);

        $lifecycle = $this->app->make(UploadSessionLifecycle::class);
        $result = $lifecycle->expire($session);

        // Status remains Attached, no S3 HeadObject or DeleteObject was called
        $this->assertSame(UploadSessionStatus::Attached, $result->status);
        $this->assertSame(UploadSessionStatus::Attached, $session->fresh()->status);
        $this->assertSame(0, $mock->count());
    }

    public function test_session_reaper_idempotency(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_reaper_disk', ['handler' => $mock]);

        // No open sessions in the database
        $this->artisan('documents:clean-orphaned')
            ->expectsOutputToContain('Expired 0 stale upload session(s).')
            ->assertExitCode(0);

        // Second run also succeeds cleanly
        $this->artisan('documents:clean-orphaned')
            ->expectsOutputToContain('Expired 0 stale upload session(s).')
            ->assertExitCode(0);
    }
}
