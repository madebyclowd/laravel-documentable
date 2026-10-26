<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class LifecycleReaperTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        FakeMultipartUploadDriver::reset();

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'ATTACHMENT',
            'name' => 'Attachment',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'uploads',
        ], $overrides));
    }

    protected function staged(DocumentType $type, string $body = 'content'): UploadSession
    {
        return $this->service->stageUpload(UploadedFile::fake()->createWithContent('a.txt', $body), $type, '42');
    }

    public function test_a_staged_upload_past_expiry_is_expired_and_its_object_deleted(): void
    {
        $session = $this->staged($this->makeType());
        $session->update(['expires_at' => now()->subHour()]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Expired, $session->fresh()->status);
        $this->assertFalse(Storage::disk('test_disk')->exists($session->path));
    }

    public function test_a_staged_upload_within_its_ttl_is_untouched(): void
    {
        $session = $this->staged($this->makeType());

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Uploaded, $session->fresh()->status);
        $this->assertTrue(Storage::disk('test_disk')->exists($session->path));
    }

    public function test_an_attached_session_is_never_touched_and_keeps_its_object(): void
    {
        $session = $this->staged($this->makeType());
        $document = $this->service->attach($session, TestModel::create(['name' => 'o']));
        $session->update(['expires_at' => now()->subYear()]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Attached, $session->fresh()->status);
        $this->assertTrue(Storage::disk('test_disk')->exists($document->storageFile->path));
    }

    public function test_a_committed_document_with_no_owner_is_never_touched_regardless_of_age(): void
    {
        $storageFile = StorageFile::create(['file_hash' => 'h', 'disk' => 'test_disk', 'path' => 'uploads/x', 'mime_type' => 'text/plain', 'size_bytes' => 1]);
        $document = Document::create([
            'storage_file_id' => $storageFile->id,
            'document_type_id' => $this->makeType()->id,
            'document_group_id' => (string) Str::uuid(),
            'client_filename' => 'old.txt',
            'status' => 'committed',
        ]);
        $document->forceFill(['created_at' => now()->subYears(5)])->save();

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertNotNull(Document::find($document->id));
    }

    public function test_a_stale_multipart_session_is_aborted_on_the_provider_and_expired(): void
    {
        $session = $this->service->initiateMultipartUpload($this->makeType(), 'big.bin', 'user-1');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'partial data');
        $session->update(['expires_at' => now()->subDay()]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Expired, $session->fresh()->status);
        $this->assertArrayNotHasKey($session->upload_id, FakeMultipartUploadDriver::$sessions);
    }

    public function test_an_uploaded_multipart_session_has_its_assembled_object_deleted_when_it_expires(): void
    {
        $session = $this->service->initiateMultipartUpload($this->makeType(), 'big.bin', 'user-1');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'hello world');
        $uploaded = $this->service->completeMultipartUpload($session);
        $uploaded->update(['expires_at' => now()->subDay()]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Expired, $session->fresh()->status);
        $this->assertFalse(Storage::disk('test_disk')->exists($session->path));
    }

    public function test_the_reaper_is_idempotent(): void
    {
        $type = $this->makeType();
        $staged = $this->staged($type);
        $staged->update(['expires_at' => now()->subHour()]);
        $multipart = $this->service->initiateMultipartUpload($type, 'big.bin', 'user-1');
        $multipart->update(['expires_at' => now()->subDay()]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);
        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Expired, $staged->fresh()->status);
        $this->assertSame(UploadSessionStatus::Expired, $multipart->fresh()->status);
    }

    public function test_the_hours_override_ignores_the_stored_expiry(): void
    {
        $session = $this->staged($this->makeType());
        $session->update(['expires_at' => now()->addDays(30)]);
        $session->forceFill(['created_at' => now()->subHours(10)])->save();

        $this->artisan('documents:clean-orphaned', ['--hours' => 5])->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Expired, $session->fresh()->status);
    }
}
