<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class DocumentModelApiTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected DocumentType $type;

    protected TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        $this->service = $this->app->make(DocumentService::class);
        $this->type = DocumentType::create([
            'code' => 'INVOICE',
            'name' => 'Invoice',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'invoices',
            'allows_multiple' => true,
            'requires_versioning' => true,
        ]);
        $this->owner = TestModel::create(['name' => 'Owner']);
    }

    public function test_document_accessors_return_expected_values(): void
    {
        $file = UploadedFile::fake()->createWithContent('Report_2026.PDF', 'pdf-content-bytes');
        $document = $this->service->upload($file, $this->type, $this->owner);

        // Fresh instance without eager loaded relations
        $fresh = Document::findOrFail($document->id);
        $this->assertFalse($fresh->relationLoaded('storageFile'));

        $this->assertSame('Report_2026.PDF', $fresh->filename);
        $this->assertSame(strlen('pdf-content-bytes'), $fresh->size);
        $this->assertSame('application/pdf', $fresh->mime);
        $this->assertSame('pdf', $fresh->extension);
    }

    public function test_document_extension_is_lowercase_and_empty_when_none(): void
    {
        $fileWithoutExt = UploadedFile::fake()->createWithContent('no_extension', 'bytes');
        $doc1 = $this->service->upload($fileWithoutExt, $this->type, $this->owner);
        $this->assertSame('', Document::findOrFail($doc1->id)->extension);

        $fileUpper = UploadedFile::fake()->createWithContent('IMAGE.JPEG', 'bytes');
        $doc2 = $this->service->upload($fileUpper, $this->type, $this->owner);
        $this->assertSame('jpeg', Document::findOrFail($doc2->id)->extension);
    }

    public function test_accessors_load_storage_file_lazily_once(): void
    {
        $file = UploadedFile::fake()->createWithContent('test.txt', 'hello');
        $document = $this->service->upload($file, $this->type, $this->owner);

        $fresh = Document::findOrFail($document->id);

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Accessing multiple storageFile-backed accessors only runs one query
        $size = $fresh->size;
        $mime = $fresh->mime;

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(5, $size);
        $this->assertSame('text/plain', $mime);
        $this->assertCount(1, $queries);
    }

    public function test_accessors_throw_logic_exception_when_storage_file_missing(): void
    {
        $document = new Document([
            'client_filename' => 'orphan.txt',
            'status' => DocumentStatus::Committed,
        ]);
        $document->id = (string) str()->uuid();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Document {$document->id} has no storage file.");

        $document->size;
    }

    public function test_temporary_url_delegates_to_document_service(): void
    {
        $file = UploadedFile::fake()->createWithContent('test.txt', 'hello');
        $document = $this->service->upload($file, $this->type, $this->owner);

        $url = $document->temporaryUrl();
        $this->assertNotEmpty($url);
        $this->assertStringStartsWith('http', $url);
    }

    public function test_scopes_filter_correctly_and_run_single_query(): void
    {
        $otherType = DocumentType::create([
            'code' => 'RECEIPT',
            'name' => 'Receipt',
            'max_size_mb' => 5,
            'disk' => 's3',
            'path_prefix' => 'receipts',
            'allows_multiple' => false,
            'requires_versioning' => false,
        ]);

        $old = $this->service->upload(UploadedFile::fake()->create('inv1.txt', 1), $this->type, $this->owner);
        $new = $this->service->upload(UploadedFile::fake()->create('inv1-v2.txt', 1), $this->type, $this->owner, [], $old->document_group_id);
        $other = $this->service->upload(UploadedFile::fake()->create('inv2.txt', 1), $this->type, $this->owner);
        $rec1 = $this->service->upload(UploadedFile::fake()->create('rec1.txt', 1), $otherType, $this->owner);
        $stillProcessing = $this->service->upload(UploadedFile::fake()->create('inv3.txt', 1), $this->type, $this->owner);
        $stillProcessing->forceFill(['status' => DocumentStatus::Processing])->save();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $results = Document::latestVersion()->ofType('INVOICE')->committed()->get();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertEqualsCanonicalizing([$new->id, $other->id], $results->pluck('id')->all(), 'The superseded version, the other type and the document still processing are left out.');
    }

    public function test_document_of_type_returns_latest_version_only(): void
    {
        $singleType = DocumentType::create([
            'code' => 'AVATAR',
            'name' => 'Avatar',
            'max_size_mb' => 5,
            'disk' => 's3',
            'path_prefix' => 'avatars',
            'allows_multiple' => false,
            'requires_versioning' => true,
        ]);

        $v1 = $this->service->upload(UploadedFile::fake()->create('v1.png', 1), $singleType, $this->owner);
        $v2 = $this->service->upload(UploadedFile::fake()->create('v2.png', 1), $singleType, $this->owner);

        $latestDoc = $this->owner->documentOfType('AVATAR')->first();

        $this->assertNotNull($latestDoc);
        $this->assertSame($v2->id, $latestDoc->id);
        $this->assertNotSame($v1->id, $latestDoc->id);
    }

    public function test_document_of_type_lazy_is_one_query(): void
    {
        $this->service->upload(UploadedFile::fake()->create('doc.txt', 1), $this->type, $this->owner);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $doc = $this->owner->documentOfType('INVOICE')->first();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotNull($doc);
        $this->assertCount(1, $queries);
    }

    public function test_eager_loading_document_of_type_does_not_n_plus_one_for_three_owners(): void
    {
        $owner1 = TestModel::create(['name' => 'Owner 1']);
        $owner2 = TestModel::create(['name' => 'Owner 2']);
        $owner3 = TestModel::create(['name' => 'Owner 3']);

        $first = $this->service->upload(UploadedFile::fake()->create('doc1.txt', 1), $this->type, $owner1);
        $second = $this->service->upload(UploadedFile::fake()->create('doc1-v2.txt', 1), $this->type, $owner1, [], $first->document_group_id);
        $this->service->upload(UploadedFile::fake()->create('doc2.txt', 1), $this->type, $owner2);
        $this->service->upload(UploadedFile::fake()->create('doc3.txt', 1), $this->type, $owner3);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $owners = TestModel::whereIn('id', [$owner1->id, $owner2->id, $owner3->id])
            ->withDocumentOfType('INVOICE')
            ->get();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // 1 query for owners + 1 query for documents eager load = 2 queries total
        $this->assertCount(2, $queries);
        $this->assertSame([$second->id], $owners->firstWhere('id', $owner1->id)->documents->pluck('id')->all(), 'Only the newest version is loaded.');

        foreach ($owners as $owner) {
            $this->assertCount(1, $owner->documents);
            $this->assertSame('INVOICE', $owner->documents->first()->documentTypeOrFail()->code);
        }
    }

    public function test_documents_of_type_returns_all_documents_of_given_type(): void
    {
        $doc1 = $this->service->upload(UploadedFile::fake()->create('doc1.txt', 1), $this->type, $this->owner);
        $doc2 = $this->service->upload(UploadedFile::fake()->create('doc2.txt', 1), $this->type, $this->owner);

        $otherType = DocumentType::create([
            'code' => 'OTHER',
            'name' => 'Other',
            'max_size_mb' => 5,
            'disk' => 's3',
            'path_prefix' => 'other',
            'allows_multiple' => true,
            'requires_versioning' => false,
        ]);
        $this->service->upload(UploadedFile::fake()->create('other.txt', 1), $otherType, $this->owner);

        $docs = $this->owner->documentsOfType('INVOICE')->get();

        $this->assertCount(2, $docs);
        $this->assertTrue($docs->contains('id', $doc1->id));
        $this->assertTrue($docs->contains('id', $doc2->id));
    }
}
