<?php

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
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

    public function test_pending_document_past_expiry_is_purged(): void
    {
        $type = $this->makeType();

        $document = $this->service->uploadDetached(
            UploadedFile::fake()->createWithContent('a.txt', 'content'),
            $type,
            [],
            pending: true,
            ttlHours: 1
        );

        $document->update(['expires_at' => now()->subHour()]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertNull(Document::withTrashed()->find($document->id));
    }

    public function test_pending_document_within_ttl_is_untouched(): void
    {
        $type = $this->makeType();

        $document = $this->service->uploadDetached(
            UploadedFile::fake()->createWithContent('a.txt', 'content'),
            $type,
            [],
            pending: true,
            ttlHours: 24
        );

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertNotNull(Document::find($document->id));
    }

    public function test_committed_document_with_null_owner_is_never_touched_regardless_of_age(): void
    {
        $type = $this->makeType();

        $document = $this->service->uploadDetached(
            UploadedFile::fake()->createWithContent('a.txt', 'content'),
            $type
        );

        $document->update(['created_at' => now()->subYears(5)]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertNotNull(Document::find($document->id));
        $this->assertSame('committed', $document->fresh()->status);
    }

    public function test_commit_transitions_pending_to_committed_and_clears_expiry(): void
    {
        $type = $this->makeType();

        $document = $this->service->uploadDetached(
            UploadedFile::fake()->createWithContent('a.txt', 'content'),
            $type,
            [],
            pending: true
        );

        $document->commit();

        $this->assertSame('committed', $document->fresh()->status);
        $this->assertNull($document->fresh()->expires_at);
    }

    public function test_stale_multipart_session_is_aborted_and_removed(): void
    {
        $type = $this->makeType();

        $session = $this->service->initiateMultipartUpload('big.bin', $type, 'user-1');
        FakeMultipartUploadDriver::uploadPart($session['upload_id'], 1, 'partial data');

        MultipartUpload::where('path', $session['path'])->update(['expires_at' => now()->subDay()]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(0, MultipartUpload::count());
    }

    public function test_reaper_is_idempotent(): void
    {
        $type = $this->makeType();

        $document = $this->service->uploadDetached(
            UploadedFile::fake()->createWithContent('a.txt', 'content'),
            $type,
            [],
            pending: true,
            ttlHours: 1
        );
        $document->update(['expires_at' => now()->subHour()]);

        $session = $this->service->initiateMultipartUpload('big.bin', $type, 'user-1');
        MultipartUpload::where('path', $session['path'])->update(['expires_at' => now()->subDay()]);

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);
        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertNull(Document::withTrashed()->find($document->id));
        $this->assertSame(0, MultipartUpload::count());
    }

    public function test_hours_override_ignores_stored_expires_at(): void
    {
        $type = $this->makeType();

        $document = $this->service->uploadDetached(
            UploadedFile::fake()->createWithContent('a.txt', 'content'),
            $type,
            [],
            pending: true,
            ttlHours: 999
        );
        $document->forceFill(['created_at' => now()->subHours(10)])->save();

        $this->artisan('documents:clean-orphaned', ['--hours' => 5])->assertExitCode(0);

        $this->assertNull(Document::withTrashed()->find($document->id));
    }
}
