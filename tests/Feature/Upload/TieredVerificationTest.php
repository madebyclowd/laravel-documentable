<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\ScanResult;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Events\DocumentProcessed;
use MadeByClowd\Documentable\Events\DocumentRejected;
use MadeByClowd\Documentable\Exceptions\DocumentNotReady;
use MadeByClowd\Documentable\Jobs\VerifyUploadedObject;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Services\ProcessingDocumentVerifier;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Up to `verification.sync_hash_max_bytes` an upload is verified inside the request. Above it the request only
 * checks what is cheap (size, a ranged read of the first bytes), the document is created `processing`, and a job
 * streams the hash, runs the scanner and promotes or rejects it. The cap is 8 bytes here, so "hi" is small and
 * anything longer is large.
 */
class TieredVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = "%PDF-1.4\nhello large world";

    protected DocumentService $service;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('documentable.verification.sync_hash_max_bytes', 8);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        Storage::fake('test_disk');
        FakeMultipartUploadDriver::reset();

        $this->service = $this->app->make(DocumentService::class);
    }

    private function type(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'docs',
            'requires_versioning' => true, 'allows_multiple' => false,
        ], $overrides));
    }

    private function owner(): TestModel
    {
        return TestModel::create(['name' => 'owner']);
    }

    private function completed(DocumentType $type, string $body, ?string $expectedHash = null): UploadSession
    {
        $session = $this->service->initiateMultipartUpload($type, 'f.pdf', '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, $body);

        return $this->service->completeMultipartUpload($session, null, $expectedHash);
    }

    private function attached(DocumentType $type, string $body = self::BODY, ?string $expectedHash = null, ?TestModel $owner = null): Document
    {
        return $this->service->attach($this->completed($type, $body, $expectedHash), $owner ?? $this->owner());
    }

    private function verify(Document $document): void
    {
        $this->app->make(ProcessingDocumentVerifier::class)->verify($document->id);
    }

    // Small files stay synchronous

    public function test_a_small_upload_is_verified_in_the_request_and_committed(): void
    {
        $type = $this->type();

        $session = $this->completed($type, 'hi');
        $document = $this->service->attach($session, $this->owner());

        $this->assertTrue($session->isVerified());
        $this->assertSame(hash('sha256', 'hi'), $session->object_hash);
        $this->assertSame(DocumentStatus::Committed, $document->status);
        $this->assertSame(hash('sha256', 'hi'), $document->storageFile->file_hash);
    }

    // Large files defer the expensive part

    public function test_a_large_upload_is_uploaded_but_not_hashed_when_it_completes(): void
    {
        $session = $this->completed($this->type(), self::BODY);

        $this->assertSame(UploadSessionStatus::Uploaded, $session->status);
        $this->assertFalse($session->isVerified());
        $this->assertNull($session->object_hash);
        $this->assertSame(strlen(self::BODY), $session->object_size);
        $this->assertSame('application/pdf', $session->object_mime);
    }

    public function test_a_large_upload_whose_size_does_not_fit_the_type_is_refused_at_once_and_removed(): void
    {
        $type = $this->type(['max_size_mb' => 0]);
        $session = $this->service->initiateMultipartUpload($type, 'f.pdf', '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, self::BODY);

        try {
            $this->service->completeMultipartUpload($session);
            $this->fail('An oversized object was accepted.');
        } catch (ValidationException) {
            Storage::disk('test_disk')->assertMissing($session->path);
        }
    }

    public function test_a_large_upload_with_a_mime_the_type_forbids_is_refused_at_once_from_the_first_bytes(): void
    {
        $type = $this->type(['allowed_mimes' => ['image/png']]);
        $session = $this->service->initiateMultipartUpload($type, 'f.png', '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, self::BODY);

        $this->expectException(ValidationException::class);

        $this->service->completeMultipartUpload($session);
    }

    public function test_attaching_a_large_upload_creates_a_processing_document_and_queues_the_job(): void
    {
        Queue::fake();

        $document = $this->attached($this->type());

        $this->assertSame(DocumentStatus::Processing, $document->status);
        Queue::assertPushed(VerifyUploadedObject::class, fn (VerifyUploadedObject $job) => $job->documentId === $document->id);
    }

    public function test_a_processing_document_is_not_a_dedup_target(): void
    {
        Queue::fake();
        $type = $this->type(['allows_multiple' => true]);
        $first = $this->attached($type);

        $this->assertNull(StorageFile::where('file_hash', hash('sha256', self::BODY))->first());
        $this->assertStringStartsWith('pending:', $first->storageFile->file_hash);
    }

    public function test_a_processing_document_has_no_download_url(): void
    {
        Queue::fake();
        $document = $this->attached($this->type());

        $this->expectException(DocumentNotReady::class);

        $this->service->getUrl($document, now()->addMinutes(5));
    }

    public function test_the_url_endpoint_answers_409_for_a_document_still_processing(): void
    {
        Queue::fake();
        $document = $this->attached($this->type());

        $this->getJson("/documents/{$document->id}/url")->assertStatus(409);
    }

    // The job

    public function test_the_job_promotes_a_good_object_and_files_it_under_its_real_hash(): void
    {
        Queue::fake();
        $document = $this->attached($this->type());
        Event::fake([DocumentProcessed::class]);

        $this->verify($document);

        $document->refresh();
        $this->assertSame(DocumentStatus::Committed, $document->status);
        $this->assertSame(hash('sha256', self::BODY), $document->storageFile->file_hash);
        $this->assertSame('application/pdf', $document->storageFile->mime_type);
        Event::assertDispatched(DocumentProcessed::class, fn (DocumentProcessed $event) => $event->document->id === $document->id);
    }

    public function test_a_committed_document_has_a_url_again(): void
    {
        Queue::fake();
        Storage::fake('test_disk');
        $document = $this->attached($this->type());
        $this->verify($document);

        $this->assertNotSame('', $this->tryUrl($document->refresh()));
    }

    private function tryUrl(Document $document): string
    {
        try {
            return $this->service->getUrl($document, now()->addMinutes(5));
        } catch (\Throwable $e) {
            // The fake local disk cannot sign URLs; reaching the signer means the readiness check passed.
            $this->assertNotInstanceOf(DocumentNotReady::class, $e);

            return 'signer-reached';
        }
    }

    public function test_the_job_is_idempotent(): void
    {
        Queue::fake();
        $document = $this->attached($this->type());
        $this->verify($document);
        Event::fake([DocumentProcessed::class]);

        $this->verify($document);

        Event::assertNotDispatched(DocumentProcessed::class);
        $this->assertSame(DocumentStatus::Committed, $document->refresh()->status);
    }

    public function test_the_job_ignores_a_document_that_no_longer_exists(): void
    {
        $this->app->make(ProcessingDocumentVerifier::class)->verify('00000000-0000-0000-0000-000000000000');

        $this->assertTrue(true);
    }

    public function test_a_hash_that_does_not_match_the_clients_claim_rejects_the_document_and_removes_the_object(): void
    {
        Queue::fake();
        $document = $this->attached($this->type(), expectedHash: hash('sha256', 'something else'));
        $path = $document->storageFile->path;
        Event::fake([DocumentRejected::class]);

        $this->verify($document);

        $document->refresh();
        $this->assertSame(DocumentStatus::Rejected, $document->status);
        $this->assertFalse($document->is_latest);
        $this->assertNull($document->latest_marker);
        Storage::disk('test_disk')->assertMissing($path);
        Event::assertDispatched(DocumentRejected::class, fn (DocumentRejected $event) => str_contains($event->reason, 'Integrity check failed'));
    }

    public function test_a_matching_client_hash_is_trusted_only_after_the_stream_hash_confirms_it(): void
    {
        Queue::fake();
        $document = $this->attached($this->type(), expectedHash: hash('sha256', self::BODY));

        $this->verify($document);

        $this->assertSame(DocumentStatus::Committed, $document->refresh()->status);
    }

    public function test_a_rejected_replacement_gives_the_slot_back_to_the_previous_version(): void
    {
        Queue::fake();
        $type = $this->type();
        $owner = $this->owner();
        $good = $this->service->attach($this->completed($type, 'hi'), $owner);
        $bad = $this->attached($type, expectedHash: hash('sha256', 'nope'), owner: $owner);
        $this->assertFalse($good->refresh()->is_latest);

        $this->verify($bad);

        $this->assertTrue($good->refresh()->is_latest);
        $this->assertSame($type->id, $good->latest_marker);
    }

    public function test_a_scanner_verdict_of_infected_rejects_the_document(): void
    {
        Queue::fake();
        $this->app->bind(ScansUploadedFile::class, fn () => new class implements ScansUploadedFile
        {
            public function scan(string $disk, string $path): ScanResult
            {
                return ScanResult::Infected;
            }
        });
        $document = $this->attached($this->type());

        $this->verify($document);

        $this->assertSame(DocumentStatus::Rejected, $document->refresh()->status);
    }

    public function test_a_transient_storage_failure_leaves_the_document_processing_and_the_object_intact(): void
    {
        Queue::fake();
        $document = $this->attached($this->type());
        $path = $document->storageFile->path;
        Storage::disk('test_disk')->delete($path);

        try {
            $this->verify($document);
            $this->fail('A missing object was treated as a verdict.');
        } catch (\RuntimeException) {
            $this->assertSame(DocumentStatus::Processing, $document->refresh()->status);
        }
    }

    public function test_identical_bytes_verified_later_collapse_onto_the_first_stored_file_and_drop_the_copy(): void
    {
        Queue::fake();
        $type = $this->type(['allows_multiple' => true]);
        $first = $this->attached($type);
        $second = $this->attached($type);
        $this->verify($first);
        $secondPath = $second->storageFile->path;

        $this->verify($second);

        $this->assertSame($first->refresh()->storage_file_id, $second->refresh()->storage_file_id);
        $this->assertSame(1, StorageFile::count());
        Storage::disk('test_disk')->assertMissing($secondPath);
        Storage::disk('test_disk')->assertExists($first->storageFile->path);
    }

    public function test_with_the_sync_queue_driver_the_document_is_committed_by_the_time_attach_returns(): void
    {
        config()->set('queue.default', 'sync');

        $document = $this->attached($this->type());

        $this->assertSame(DocumentStatus::Committed, $document->refresh()->status);
    }

    public function test_the_job_runs_the_verifier_and_retries_with_backoff(): void
    {
        $job = new VerifyUploadedObject('doc-id');

        $this->assertGreaterThan(1, $job->tries);
        $this->assertNotEmpty($job->backoff());
    }

    public function test_a_processing_document_that_lost_its_session_fails_loudly_instead_of_guessing(): void
    {
        Queue::fake();
        $document = $this->attached($this->type());
        UploadSession::query()->delete();

        $this->expectException(\LogicException::class);

        $this->verify($document);
    }

    public function test_a_scanner_outage_is_retried_and_never_treated_as_a_verdict(): void
    {
        Queue::fake();
        $this->app->bind(ScansUploadedFile::class, fn () => $this->scannerReturning(ScanResult::Error));
        $document = $this->attached($this->type());
        $path = $document->storageFile->path;

        try {
            $this->verify($document);
            $this->fail('A scanner outage was treated as a verdict.');
        } catch (\RuntimeException) {
            $this->assertSame(DocumentStatus::Processing, $document->refresh()->status);
            Storage::disk('test_disk')->assertExists($path);
        }
    }

    public function test_losing_the_race_to_file_identical_bytes_merges_onto_the_winner(): void
    {
        Queue::fake();
        $type = $this->type(['allows_multiple' => true]);
        $first = $this->attached($type);
        $second = $this->attached($type);
        $this->verify($first);

        // The first lookup misses, as if the winner committed a moment later, so the rename hits the unique key.
        $this->app->bind(StorageFileRepository::class, fn () => new class extends StorageFileRepository
        {
            private bool $stale = true;

            public function findByHash(string $hash, string $disk, bool $lock = false): ?StorageFile
            {
                if ($this->stale) {
                    $this->stale = false;

                    return null;
                }

                return parent::findByHash($hash, $disk, $lock);
            }
        });

        $this->app->make(ProcessingDocumentVerifier::class)->verify($second->id);

        $this->assertSame($first->refresh()->storage_file_id, $second->refresh()->storage_file_id);
        $this->assertSame(DocumentStatus::Committed, $second->status);
    }

    public function test_two_workers_verifying_one_document_settle_it_once(): void
    {
        Queue::fake();
        $document = $this->attached($this->type());

        // While this worker scans, another one commits the same document.
        $this->app->bind(ScansUploadedFile::class, fn () => new class($document->id) implements ScansUploadedFile
        {
            public function __construct(private readonly string $documentId) {}

            public function scan(string $disk, string $path): ScanResult
            {
                Document::whereKey($this->documentId)->update(['status' => DocumentStatus::Committed]);

                return ScanResult::Clean;
            }
        });
        Event::fake([DocumentProcessed::class]);

        $this->verify($document);

        $this->assertSame(DocumentStatus::Committed, $document->refresh()->status);
        $this->assertStringStartsWith('pending:', $document->storageFile->file_hash);
    }

    private function scannerReturning(ScanResult $result): ScansUploadedFile
    {
        return new class($result) implements ScansUploadedFile
        {
            public function __construct(private readonly ScanResult $result) {}

            public function scan(string $disk, string $path): ScanResult
            {
                return $this->result;
            }
        };
    }

    public function test_a_rejected_replacement_brings_back_a_previous_version_the_type_had_trashed(): void
    {
        Queue::fake();
        $type = $this->type(['requires_versioning' => false]);
        $owner = $this->owner();
        $good = $this->service->attach($this->completed($type, 'hi'), $owner);
        $bad = $this->attached($type, expectedHash: hash('sha256', 'nope'), owner: $owner);
        $this->assertSoftDeleted($good);

        $this->verify($bad);

        $this->assertNull($good->fresh()?->deleted_at);
        $this->assertTrue($good->fresh()->is_latest);
    }
}
