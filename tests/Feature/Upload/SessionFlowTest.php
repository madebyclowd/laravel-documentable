<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use BadMethodCallException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every transport funnels through an upload session and a single attach(). These tests pin the session
 * contract: who may drive a session, what verification does to a bad object, and that the slot invariant holds
 * whichever way the bytes arrived.
 */
class SessionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        Storage::fake('test_disk');
        FakeMultipartUploadDriver::reset();

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'requires_versioning' => true,
        ], $overrides));
    }

    protected function owner(): TestModel
    {
        return TestModel::create(['name' => 'owner']);
    }

    /** A single presigned upload, finished: initiate, "client" writes the object, finalize. */
    protected function presignedUploaded(DocumentType $type, string $body, string $filename = 'a.txt', ?string $userId = '42', ?UploadSessionData $declared = null): UploadSession
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();

        $session = $this->service->initiatePresignedUpload($type, $filename, $userId, $declared)['session'];
        Storage::disk('test_disk')->put($session->path, $body);

        return $this->service->finalizeDirectUpload($session);
    }

    protected function multipartUploaded(DocumentType $type, string $body, string $filename = 'a.txt', string $userId = '42'): UploadSession
    {
        $session = $this->service->initiateMultipartUpload($type, $filename, $userId);
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, $body);

        return $this->service->completeMultipartUpload($session);
    }

    // Presigned single PUT

    public function test_initiating_a_presigned_upload_creates_a_session_with_a_server_generated_path(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $type = $this->makeType();

        $started = $this->service->initiatePresignedUpload($type, '../../etc/passwd', '42');

        $session = $started['session'];
        $this->assertSame(UploadSessionStatus::Initiated, $session->status);
        $this->assertSame(UploadKind::Single, $session->kind);
        $this->assertSame('42', $session->user_id);
        $this->assertStringStartsWith('docs/', $session->path);
        $this->assertStringNotContainsString('..', $session->path);
        $this->assertSame('../../etc/passwd', $session->client_filename);
        $this->assertArrayHasKey('url', $started);
        $this->assertArrayHasKey('headers', $started);
    }

    public function test_finalizing_records_what_was_measured_and_marks_the_session_uploaded(): void
    {
        $session = $this->presignedUploaded($this->makeType(), 'hello world');

        $this->assertSame(UploadSessionStatus::Uploaded, $session->status);
        $this->assertSame(hash('sha256', 'hello world'), $session->object_hash);
        $this->assertSame(11, $session->object_size);
        $this->assertSame('text/plain', $session->object_mime);
        $this->assertSame(0, Document::count());
    }

    public function test_finalizing_an_object_that_was_never_uploaded_fails_without_touching_the_session(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $session = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', '42')['session'];

        try {
            $this->service->finalizeDirectUpload($session);
            $this->fail('Expected a missing object to be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(UploadSessionStatus::Initiated, $session->fresh()->status);
    }

    public function test_finalizing_twice_is_idempotent_and_keeps_the_stored_object(): void
    {
        $session = $this->presignedUploaded($this->makeType(), 'hello world');

        $again = $this->service->finalizeDirectUpload($session);

        $this->assertSame(UploadSessionStatus::Uploaded, $again->status);
        $this->assertTrue(Storage::disk('test_disk')->exists($session->path));
    }

    public function test_a_wrong_expected_hash_deletes_the_object_and_aborts_the_session(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $session = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', '42')['session'];
        Storage::disk('test_disk')->put($session->path, 'hello world');

        try {
            $this->service->finalizeDirectUpload($session, str_repeat('0', 64));
            $this->fail('Expected an integrity failure.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse(Storage::disk('test_disk')->exists($session->path));
        $this->assertSame(UploadSessionStatus::Aborted, $session->fresh()->status);
    }

    public function test_a_declared_size_that_does_not_match_the_object_is_refused_and_cleaned_up(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $session = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', '42', new UploadSessionData(sizeBytes: 5))['session'];
        Storage::disk('test_disk')->put($session->path, 'hello world');

        try {
            $this->service->finalizeDirectUpload($session);
            $this->fail('Expected a size mismatch.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse(Storage::disk('test_disk')->exists($session->path));
        $this->assertSame(UploadSessionStatus::Aborted, $session->fresh()->status);
    }

    public function test_an_object_the_type_does_not_allow_is_refused_and_cleaned_up(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $type = $this->makeType(['allowed_mimes' => ['application/pdf']]);
        $session = $this->service->initiatePresignedUpload($type, 'a.txt', '42')['session'];
        Storage::disk('test_disk')->put($session->path, 'hello world');

        try {
            $this->service->finalizeDirectUpload($session);
            $this->fail('Expected the mime type to be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse(Storage::disk('test_disk')->exists($session->path));
        $this->assertSame(UploadSessionStatus::Aborted, $session->fresh()->status);
    }

    public function test_an_expired_session_refuses_to_finalize(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $session = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', '42')['session'];
        Storage::disk('test_disk')->put($session->path, 'hello world');
        $session->update(['expires_at' => now()->subMinute()]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('expired');

        $this->service->finalizeDirectUpload($session->fresh());
    }

    // Multipart

    public function test_a_multipart_session_is_bound_to_a_type_and_keeps_the_provider_id_server_side(): void
    {
        $type = $this->makeType();

        $session = $this->service->initiateMultipartUpload($type, 'big.bin', '42', new UploadSessionData(sizeBytes: 11));

        $this->assertSame(UploadKind::Multipart, $session->kind);
        $this->assertSame($type->id, $session->document_type_id);
        $this->assertNotEmpty($session->upload_id);
        $this->assertArrayNotHasKey('path', $session->toArray());
        $this->assertArrayNotHasKey('upload_id', $session->toArray());
    }

    public function test_completing_a_multipart_upload_verifies_and_marks_the_session_uploaded(): void
    {
        $session = $this->multipartUploaded($this->makeType(), 'hello world');

        $this->assertSame(UploadSessionStatus::Uploaded, $session->status);
        $this->assertSame(hash('sha256', 'hello world'), $session->object_hash);
        $this->assertSame(11, $session->object_size);
    }

    public function test_a_multipart_object_that_fails_verification_is_deleted_and_the_session_aborted(): void
    {
        $type = $this->makeType(['allowed_mimes' => ['application/pdf']]);
        $session = $this->service->initiateMultipartUpload($type, 'a.txt', '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'hello world');

        try {
            $this->service->completeMultipartUpload($session);
            $this->fail('Expected the mime type to be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse(Storage::disk('test_disk')->exists($session->path));
        $this->assertSame(UploadSessionStatus::Aborted, $session->fresh()->status);
    }

    public function test_aborting_a_multipart_session_aborts_the_provider_upload(): void
    {
        $session = $this->service->initiateMultipartUpload($this->makeType(), 'a.txt', '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'x');

        $this->service->abortUploadSession($session);

        $this->assertSame(UploadSessionStatus::Aborted, $session->fresh()->status);
        $this->assertArrayNotHasKey($session->upload_id, FakeMultipartUploadDriver::$sessions);
    }

    // Attach

    public function test_attaching_creates_the_document_registers_the_file_and_closes_the_session(): void
    {
        $type = $this->makeType();
        $owner = $this->owner();
        $session = $this->presignedUploaded($type, 'hello world', 'report.txt');

        $document = $this->service->attach($session, $owner);

        $this->assertSame('report.txt', $document->client_filename);
        $this->assertSame($owner->getKey(), (int) $document->documentable_id);
        $this->assertSame(1, $document->version);
        $this->assertTrue($document->is_latest);
        $this->assertSame($session->path, $document->storageFile->path);
        $this->assertSame(UploadSessionStatus::Attached, $session->fresh()->status);
        $this->assertSame($document->storage_file_id, $session->fresh()->storage_file_id);
    }

    public function test_the_same_session_cannot_be_attached_twice(): void
    {
        $session = $this->presignedUploaded($this->makeType(), 'hello world');
        $this->service->attach($session, $this->owner());

        $this->expectException(ValidationException::class);

        $this->service->attach($session->fresh(), $this->owner());
    }

    public function test_a_session_that_was_never_uploaded_cannot_be_attached(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $session = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', '42')['session'];

        $this->expectException(ValidationException::class);

        $this->service->attach($session, $this->owner());
    }

    public function test_an_expired_session_cannot_be_attached(): void
    {
        $session = $this->presignedUploaded($this->makeType(), 'hello world');
        $session->update(['expires_at' => now()->subMinute()]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('expired');

        $this->service->attach($session->fresh(), $this->owner());
    }

    public function test_attaching_with_a_group_that_belongs_to_another_owner_is_refused(): void
    {
        $type = $this->makeType(['allows_multiple' => true]);
        $stranger = $this->owner();
        $theirs = $this->service->attach($this->presignedUploaded($type, 'theirs'), $stranger);

        $session = $this->presignedUploaded($type, 'mine');

        try {
            $this->service->attach($session, $this->owner(), new AttachOptions(documentGroupId: $theirs->document_group_id));
            $this->fail('Expected a foreign group to be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(UploadSessionStatus::Uploaded, $session->fresh()->status);
        $this->assertSame(1, Document::count());
    }

    public function test_attaching_with_an_unknown_group_is_refused(): void
    {
        $type = $this->makeType(['allows_multiple' => true]);
        $session = $this->presignedUploaded($type, 'mine');

        $this->expectException(ValidationException::class);

        $this->service->attach($session, $this->owner(), new AttachOptions(documentGroupId: '3f2a8c1e-0000-4000-8000-000000000000'));
    }

    public function test_attaching_to_the_owners_own_group_adds_a_version(): void
    {
        $type = $this->makeType(['allows_multiple' => true]);
        $owner = $this->owner();
        $first = $this->service->attach($this->presignedUploaded($type, 'one'), $owner);

        $second = $this->service->attach($this->presignedUploaded($type, 'two'), $owner, new AttachOptions(documentGroupId: $first->document_group_id));

        $this->assertSame(2, $second->version);
        $this->assertSame($first->document_group_id, $second->document_group_id);
        $this->assertFalse($first->fresh()->is_latest);
    }

    public function test_attach_options_metadata_is_stored_on_the_document(): void
    {
        $document = $this->service->attach(
            $this->presignedUploaded($this->makeType(), 'hello'),
            $this->owner(),
            new AttachOptions(metadata: ['source' => 'portal'])
        );

        $this->assertSame('portal', $document->metadata['source']);
        $this->assertSame('text/plain', $document->metadata['mime_type']);
    }

    // Ownerless create-then-attach

    public function test_staging_a_server_upload_without_an_owner_returns_an_uploaded_session_to_attach_later(): void
    {
        $type = $this->makeType();

        $session = $this->service->stageUpload(UploadedFile::fake()->createWithContent('form.txt', 'draft'), $type, '42');

        $this->assertSame(UploadSessionStatus::Uploaded, $session->status);
        $this->assertSame(0, Document::count());

        $document = $this->service->attach($session, $this->owner());

        $this->assertSame('form.txt', $document->client_filename);
        $this->assertSame(1, Document::count());
    }

    public function test_a_server_upload_with_an_owner_attaches_in_one_call_and_leaves_an_attached_session(): void
    {
        $document = $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', 'bytes'), $this->makeType(), $this->owner());

        $session = UploadSession::firstOrFail();
        $this->assertSame(UploadSessionStatus::Attached, $session->status);
        $this->assertSame($document->storage_file_id, $session->storage_file_id);
    }

    // The slot invariant, whatever the transport

    /**
     * @return array<string, array{0: string}>
     */
    public static function transports(): array
    {
        return [
            'server upload' => ['server'],
            'single presigned' => ['presigned'],
            'multipart' => ['multipart'],
            'stage then attach' => ['staged'],
        ];
    }

    #[DataProvider('transports')]
    public function test_exactly_one_latest_per_owner_and_type_whatever_the_transport(string $transport): void
    {
        $type = $this->makeType();
        $owner = $this->owner();

        foreach (['one', 'two', 'three'] as $body) {
            match ($transport) {
                'server' => $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', $body), $type, $owner),
                'presigned' => $this->service->attach($this->presignedUploaded($type, $body), $owner),
                'multipart' => $this->service->attach($this->multipartUploaded($type, $body), $owner),
                'staged' => $this->service->attach($this->service->stageUpload(UploadedFile::fake()->createWithContent('a.txt', $body), $type, '42'), $owner),
            };
        }

        $documents = Document::where('documentable_id', $owner->getKey())->get();

        $this->assertCount(3, $documents);
        $this->assertSame(1, $documents->where('is_latest', true)->count());
        $this->assertSame(1, $documents->whereNotNull('latest_marker')->count());
        $this->assertSame(3, $documents->where('is_latest', true)->first()->version);
    }

    // Dedup moves to attach

    public function test_identical_bytes_from_two_sessions_share_one_stored_file_and_the_redundant_object_is_removed(): void
    {
        $type = $this->makeType(['allows_multiple' => true]);
        $first = $this->presignedUploaded($type, 'same bytes');
        $second = $this->presignedUploaded($type, 'same bytes');

        $a = $this->service->attach($first, $this->owner());
        $b = $this->service->attach($second, $this->owner());

        $this->assertSame($a->storage_file_id, $b->storage_file_id);
        $this->assertSame(1, StorageFile::count());
        $this->assertTrue(Storage::disk('test_disk')->exists($first->path));
        $this->assertFalse(Storage::disk('test_disk')->exists($second->path));
    }

    // Removed API

    public function test_the_detached_and_pending_api_is_gone(): void
    {
        foreach (['uploadDetached', 'reassociateDocument'] as $method) {
            $this->assertFalse(method_exists($this->service, $method), "{$method} should be removed");
        }

        $document = $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', 'x'), $this->makeType(), $this->owner());

        $this->expectException(BadMethodCallException::class);

        $document->commit();
    }

    // Races: a stale copy of a session must never delete an object a document now uses

    public function test_a_stale_failing_finalize_does_not_delete_an_object_that_was_already_attached(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $session = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', '42')['session'];
        Storage::disk('test_disk')->put($session->path, 'hello world');
        $stale = UploadSession::findOrFail($session->id);

        $document = $this->service->attach($this->service->finalizeDirectUpload($session), $this->owner());

        try {
            $this->service->finalizeDirectUpload($stale, str_repeat('0', 64));
            $this->fail('Expected the stale finalize to fail verification.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertTrue(Storage::disk('test_disk')->exists($document->storageFile->path));
        $this->assertSame(UploadSessionStatus::Attached, $session->fresh()->status);
    }

    public function test_a_stale_finalize_that_loses_the_race_returns_the_winning_result(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $session = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', '42')['session'];
        Storage::disk('test_disk')->put($session->path, 'hello world');
        $stale = UploadSession::findOrFail($session->id);

        $this->service->finalizeDirectUpload($session);
        $result = $this->service->finalizeDirectUpload($stale);

        $this->assertSame(UploadSessionStatus::Uploaded, $result->status);
        $this->assertSame(hash('sha256', 'hello world'), $result->object_hash);
    }

    public function test_an_ended_session_cannot_be_finalized(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $session = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', '42')['session'];
        $this->service->abortUploadSession($session);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('can no longer be used');

        $this->service->finalizeDirectUpload($session->fresh());
    }

    public function test_a_stale_expiry_does_not_delete_an_object_that_was_attached_in_the_meantime(): void
    {
        $session = $this->service->stageUpload(UploadedFile::fake()->createWithContent('a.txt', 'bytes'), $this->makeType(), '42');
        $stale = UploadSession::findOrFail($session->id);

        $document = $this->service->attach($session, $this->owner());

        $result = $this->service->expireUploadSession($stale);

        $this->assertSame(UploadSessionStatus::Attached, $result->status);
        $this->assertTrue(Storage::disk('test_disk')->exists($document->storageFile->path));
    }

    public function test_expiring_an_uploaded_session_that_owns_a_stored_file_removes_the_file_when_nothing_references_it(): void
    {
        $storageFile = StorageFile::create(['file_hash' => 'h', 'disk' => 'test_disk', 'path' => 'docs/orphan', 'mime_type' => 'text/plain', 'size_bytes' => 1]);
        Storage::disk('test_disk')->put('docs/orphan', 'x');
        $session = UploadSession::create([
            'document_type_id' => $this->makeType()->id,
            'disk' => 'test_disk',
            'kind' => UploadKind::Single,
            'client_filename' => 'a.txt',
            'path' => 'docs/orphan',
            'status' => UploadSessionStatus::Uploaded,
            'storage_file_id' => $storageFile->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->service->expireUploadSession($session);

        $this->assertSame(UploadSessionStatus::Expired, $session->fresh()->status);
        $this->assertNull(StorageFile::find($storageFile->id));
        $this->assertFalse(Storage::disk('test_disk')->exists('docs/orphan'));
    }
}
