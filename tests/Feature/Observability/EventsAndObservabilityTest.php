<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Observability;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentPurged;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Events\DocumentVersionSuperseded;
use MadeByClowd\Documentable\Events\MultipartUploadAborted;
use MadeByClowd\Documentable\Events\MultipartUploadInitiated;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentAccessLog;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class EventsAndObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test_disk');
        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
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

    public function test_document_uploaded_fires_once_on_upload(): void
    {
        Event::fake([DocumentUploaded::class]);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $file = UploadedFile::fake()->create('a.txt', 1);

        $document = $this->service->upload($file, $type, $owner);

        Event::assertDispatchedTimes(DocumentUploaded::class, 1);
        Event::assertDispatched(DocumentUploaded::class, fn ($e) => $e->document->is($document));
    }

    public function test_document_version_superseded_fires_on_reupload(): void
    {
        Event::fake([DocumentVersionSuperseded::class]);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $first = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);
        $second = $this->service->upload(UploadedFile::fake()->create('b.txt', 1), $type, $owner);

        Event::assertDispatchedTimes(DocumentVersionSuperseded::class, 1);
        Event::assertDispatched(DocumentVersionSuperseded::class, function ($e) use ($first, $second) {
            return $e->previous->is($first) && $e->new->is($second);
        });
    }

    public function test_document_deleted_fires_on_delete(): void
    {
        Event::fake([DocumentDeleted::class]);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->service->delete($document);

        Event::assertDispatchedTimes(DocumentDeleted::class, 1);
    }

    public function test_document_purged_fires_with_storage_file_deleted_flag(): void
    {
        Event::fake([DocumentPurged::class]);

        $type = $this->makeType(['requires_versioning' => false]);
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->service->purge($document);

        Event::assertDispatched(DocumentPurged::class, fn ($e) => $e->storageFileAlsoDeleted === true);
    }

    public function test_multipart_upload_initiated_and_aborted_events_fire(): void
    {
        Event::fake([MultipartUploadInitiated::class, MultipartUploadAborted::class]);

        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);

        $session = $this->service->initiateMultipartUpload($type, 'f.bin', 'user-1');
        Event::assertDispatchedTimes(MultipartUploadInitiated::class, 1);

        $this->service->abortUploadSession($session);
        Event::assertDispatchedTimes(MultipartUploadAborted::class, 1);
    }

    public function test_access_log_stays_empty_when_disabled(): void
    {
        config()->set('documentable.audit.access_log', false);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->service->getUrl($document, now()->addMinutes(5));

        $this->assertSame(0, DocumentAccessLog::count());
    }

    public function test_access_log_gets_a_row_per_get_url_call_when_enabled(): void
    {
        config()->set('documentable.audit.access_log', true);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->service->getUrl($document, now()->addMinutes(5));
        $this->service->getUrl($document, now()->addMinutes(5), 'attachment');

        $this->assertSame(2, DocumentAccessLog::count());
        $this->assertSame(['view', 'download'], DocumentAccessLog::orderBy('created_at')->pluck('action')->all());
    }

    public function test_created_by_populated_from_authenticated_actor_when_audit_enabled(): void
    {
        config()->set('documentable.audit.enabled', true);
        Auth::shouldReceive('id')->andReturn('actor-42');
        Auth::shouldReceive('user')->andReturn(null);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->assertSame('actor-42', $document->created_by);
    }

    public function test_created_by_null_when_audit_disabled_even_with_authenticated_actor(): void
    {
        config()->set('documentable.audit.enabled', false);
        Auth::shouldReceive('id')->andReturn('actor-42');
        Auth::shouldReceive('user')->andReturn(null);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->assertNull($document->created_by);
    }

    public function test_created_by_null_in_unauthenticated_context_even_when_audit_enabled(): void
    {
        config()->set('documentable.audit.enabled', true);
        Auth::shouldReceive('id')->andReturn(null);
        Auth::shouldReceive('user')->andReturn(null);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->assertNull($document->created_by);
    }

    public function test_deleted_by_populated_on_delete_when_audit_enabled(): void
    {
        config()->set('documentable.audit.enabled', true);
        Auth::shouldReceive('id')->andReturn('actor-99');
        Auth::shouldReceive('user')->andReturn(null);

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->service->delete($document);

        $this->assertSame('actor-99', Document::withTrashed()->find($document->id)->deleted_by);
    }

    public function test_a_multipart_upload_is_verified_against_its_real_bytes(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);

        $session = $this->service->initiateMultipartUpload($type, 'f.bin', 'user-1');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'hello world');

        $uploaded = $this->service->completeMultipartUpload($session, null, hash('sha256', 'hello world'));

        $this->assertSame(hash('sha256', 'hello world'), $uploaded->object_hash);
    }

    public function test_a_multipart_upload_with_a_wrong_expected_hash_is_rejected(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);

        $session = $this->service->initiateMultipartUpload($type, 'f.bin', 'user-1');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'hello world');

        $this->expectException(ValidationException::class);

        $this->service->completeMultipartUpload($session, null, hash('sha256', 'something else'));
    }
}
