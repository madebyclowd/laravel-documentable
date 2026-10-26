<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use ArrayObject;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Events\DocumentVersionSuperseded;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentAccessLog;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Pins what DocumentService does today wherever the rest of the suite is thin, so the decomposition into
 * collaborators can be proven behavior-preserving. These tests describe current behavior, good or bad:
 * they must stay green and unmodified through the refactor, and a bug they expose is fixed in its own change.
 */
class DocumentServiceCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    /** @var ArrayObject<int, array{path: string, expiration: DateTimeInterface, options: array<string, mixed>}> */
    protected ArrayObject $urlCalls;

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

    protected function uploadWithUrlRecorder(string $clientName): Document
    {
        // Laravel runs the callback bound to the filesystem adapter, so it records through a captured object, not $this.
        $this->urlCalls = $calls = new ArrayObject;
        Storage::disk('test_disk')->buildTemporaryUrlsUsing(function ($path, $expiration, $options) use ($calls) {
            $calls[] = ['path' => $path, 'expiration' => $expiration, 'options' => $options];

            return 'https://signed.test/'.$path;
        });

        return $this->service->upload(UploadedFile::fake()->createWithContent($clientName, 'bytes'), $this->makeType(), $this->owner());
    }

    // getUrl

    public function test_get_url_defaults_to_an_inline_disposition_with_the_client_filename_and_passes_the_expiry_through(): void
    {
        $document = $this->uploadWithUrlRecorder('report.txt');
        $expiry = now()->addMinutes(7);

        $url = $this->service->getUrl($document, $expiry);

        $this->assertSame('https://signed.test/'.$document->storageFile->path, $url);
        $this->assertCount(1, $this->urlCalls);
        $this->assertSame($document->storageFile->path, $this->urlCalls[0]['path']);
        $this->assertSame($expiry, $this->urlCalls[0]['expiration']);
        $this->assertSame(['ResponseContentDisposition' => 'inline; filename="report.txt"'], $this->urlCalls[0]['options']);
    }

    public function test_get_url_attachment_disposition_is_forwarded_verbatim(): void
    {
        $document = $this->uploadWithUrlRecorder('report.txt');

        $this->service->getUrl($document, now()->addMinute(), 'attachment');

        $this->assertSame('attachment; filename="report.txt"', $this->urlCalls[0]['options']['ResponseContentDisposition']);
    }

    public function test_get_url_strips_quotes_and_control_characters_from_the_filename(): void
    {
        $document = $this->uploadWithUrlRecorder('report.txt');
        $document->client_filename = "a\"b\r\nc\x00d.pdf";

        $this->service->getUrl($document, now()->addMinute());

        $this->assertSame('inline; filename="abcd.pdf"', $this->urlCalls[0]['options']['ResponseContentDisposition']);
    }

    public function test_sanitize_header_filename_removes_only_quotes_and_control_characters(): void
    {
        $this->assertSame('plain name (1).pdf', DocumentService::sanitizeHeaderFilename('plain name (1).pdf'));
        $this->assertSame('ab.pdf', DocumentService::sanitizeHeaderFilename("a\tb\"\x7F.pdf"));
        $this->assertSame('semi;colon\'s.txt', DocumentService::sanitizeHeaderFilename('semi;colon\'s.txt'));
        $this->assertSame('', DocumentService::sanitizeHeaderFilename("\"\r\n"));
    }

    public function test_get_url_logs_view_for_inline_and_download_only_for_the_attachment_disposition(): void
    {
        config()->set('documentable.audit.access_log', true);
        $document = $this->uploadWithUrlRecorder('report.txt');

        $this->service->getUrl($document, now()->addMinute());
        $this->service->getUrl($document, now()->addMinute(), 'attachment');
        $this->service->getUrl($document, now()->addMinute(), 'anything-else');

        $this->assertSame(
            ['view', 'download', 'view'],
            DocumentAccessLog::where('document_id', $document->id)->orderBy('created_at')->orderBy('id')->pluck('action')->all()
        );
    }

    // delete

    public function test_delete_soft_deletes_the_latest_and_frees_its_slot_but_keeps_the_blob_and_storage_row(): void
    {
        Event::fake([DocumentDeleted::class]);
        $type = $this->makeType();
        $document = $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', 'bytes'), $type, $this->owner());
        $path = $document->storageFile->path;

        $this->assertTrue($this->service->delete($document));

        $fresh = Document::withTrashed()->find($document->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertNull($fresh->latest_marker);
        $this->assertTrue(Storage::disk('test_disk')->exists($path));
        $this->assertNotNull(StorageFile::find($document->storage_file_id));
        Event::assertDispatchedTimes(DocumentDeleted::class, 1);
    }

    // events

    public function test_a_versioned_reupload_dispatches_superseded_before_uploaded(): void
    {
        $order = [];
        Event::listen(DocumentVersionSuperseded::class, function () use (&$order) {
            $order[] = 'superseded';
        });
        Event::listen(DocumentUploaded::class, function () use (&$order) {
            $order[] = 'uploaded';
        });

        $type = $this->makeType();
        $owner = $this->owner();
        $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', 'one'), $type, $owner);
        $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', 'two'), $type, $owner);

        $this->assertSame(['uploaded', 'superseded', 'uploaded'], $order);
    }

    // multipart completion

    /**
     * @return array{0: array{path: string, upload_id: string}, 1: DocumentType}
     */
    protected function startMultipart(array $typeOverrides = []): array
    {
        $type = $this->makeType($typeOverrides + ['code' => 'BIG', 'path_prefix' => 'uploads']);
        $session = $this->service->initiateMultipartUpload('big.txt', $type, 'user-1');
        FakeMultipartUploadDriver::uploadPart($session['upload_id'], 1, 'hello ');
        FakeMultipartUploadDriver::uploadPart($session['upload_id'], 2, 'world');

        return [$session, $type];
    }

    public function test_completing_a_multipart_upload_creates_the_document_removes_the_session_and_fires_one_event_after_both(): void
    {
        [$session, $type] = $this->startMultipart();
        $seen = [];
        Event::listen(DocumentUploaded::class, function (DocumentUploaded $event) use (&$seen) {
            $seen[] = [
                'sessions' => MultipartUpload::count(),
                'committed' => Document::whereKey($event->document->id)->exists(),
            ];
        });

        $document = $this->service->completeMultipartUpload($session['path'], $session['upload_id'], 'user-1', $type, $this->owner(), 'big.txt');

        $this->assertSame([['sessions' => 0, 'committed' => true]], $seen);
        $this->assertTrue($document->metadata['multipart']);
        $this->assertSame(hash('sha256', 'hello world'), $document->storageFile->file_hash);
        $this->assertSame($session['path'], $document->storageFile->path);
    }

    public function test_a_multipart_upload_rejected_after_assembly_deletes_the_object_keeps_the_session_and_creates_nothing(): void
    {
        [$session, $type] = $this->startMultipart(['allowed_mimes' => ['application/pdf']]);

        try {
            $this->service->completeMultipartUpload($session['path'], $session['upload_id'], 'user-1', $type, $this->owner(), 'big.txt');
            $this->fail('Expected the assembled object to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse(Storage::disk('test_disk')->exists($session['path']));
        $this->assertSame(1, MultipartUpload::count());
        $this->assertSame(0, Document::count());
        $this->assertSame(0, StorageFile::count());
    }

    public function test_a_multipart_upload_with_a_wrong_expected_hash_deletes_the_object_and_creates_nothing(): void
    {
        [$session, $type] = $this->startMultipart();

        try {
            $this->service->completeMultipartUpload($session['path'], $session['upload_id'], 'user-1', $type, $this->owner(), 'big.txt', null, str_repeat('0', 64));
            $this->fail('Expected an integrity failure.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse(Storage::disk('test_disk')->exists($session['path']));
        $this->assertSame(0, Document::count());
    }
}
