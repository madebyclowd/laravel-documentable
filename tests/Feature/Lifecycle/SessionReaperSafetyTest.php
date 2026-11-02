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
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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

        $s3Calls = 0;
        $mock->append(function () use (&$s3Calls) {
            $s3Calls++;

            return new Result;
        });

        $lifecycle = $this->app->make(UploadSessionLifecycle::class);
        $result = $lifecycle->expire($session);

        // Status remains Attached, no S3 HeadObject or DeleteObject was called
        $this->assertSame(UploadSessionStatus::Attached, $result->status);
        $this->assertSame(UploadSessionStatus::Attached, $session->fresh()->status);
        $this->assertSame(0, $s3Calls);
    }

    /**
     * @return array<string, array{UploadKind, ?string, ?string, bool}>
     */
    public static function providerUploadCases(): array
    {
        return [
            'open multipart upload is aborted' => [UploadKind::Multipart, 'upload-1', null, true],
            'assembled multipart upload is not aborted' => [UploadKind::Multipart, 'upload-1', 'a-hash', false],
            'multipart without a provider id has nothing to abort' => [UploadKind::Multipart, null, null, false],
            'single PUT never aborts a provider upload' => [UploadKind::Single, 'upload-1', null, false],
        ];
    }

    #[DataProvider('providerUploadCases')]
    public function test_only_an_open_multipart_upload_is_aborted_on_the_provider(UploadKind $kind, ?string $uploadId, ?string $objectHash, bool $aborted): void
    {
        FakeMultipartUploadDriver::reset();
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        config()->set('filesystems.disks.fake_local', ['driver' => 'local', 'root' => storage_path('framework/testing/reaper')]);

        $type = DocumentType::create(['code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 'fake_local', 'path_prefix' => 'uploads']);
        $session = UploadSession::create([
            'document_type_id' => $type->id,
            'disk' => 'fake_local',
            'kind' => $kind,
            'client_filename' => 'f.bin',
            'path' => 'uploads/f.bin',
            'upload_id' => $uploadId,
            'object_hash' => $objectHash,
            'status' => UploadSessionStatus::Initiated,
            'expires_at' => now()->subHour(),
        ]);
        FakeMultipartUploadDriver::$sessions['upload-1'] = ['path' => 'uploads/f.bin', 'parts' => []];

        $this->app->make(UploadSessionLifecycle::class)->expire($session);

        $this->assertSame(UploadSessionStatus::Expired, $session->fresh()->status);
        $this->assertSame(! $aborted, isset(FakeMultipartUploadDriver::$sessions['upload-1']));

        FakeMultipartUploadDriver::reset();
    }

    public function test_session_reaper_keeps_the_session_open_when_the_object_cannot_even_be_inspected(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_reaper_disk', ['handler' => $mock]);
        $session = $this->makeExpiredSession('s3_reaper_disk', 'uploads/file.bin');
        $mock->append(new S3Exception('denied', new Command('HeadObject'), ['code' => 'AccessDenied', 'response' => new Response(403)]));

        $this->artisan('documents:clean-orphaned')->assertExitCode(1);

        $this->assertSame(UploadSessionStatus::Initiated, $session->fresh()->status);
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
