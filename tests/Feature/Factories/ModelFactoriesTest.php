<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Factories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class ModelFactoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Model::shouldBeStrict(false);
        parent::tearDown();
    }

    public function test_factories_create_valid_rows_under_strict_mode(): void
    {
        Model::shouldBeStrict(true);

        $type = DocumentType::factory()->create();
        $this->assertDatabaseHas('document_types', ['id' => $type->id]);

        $storageFile = StorageFile::factory()->create();
        $this->assertDatabaseHas('storage_files', ['id' => $storageFile->id]);

        $document = Document::factory()->create();
        $this->assertDatabaseHas('documents', ['id' => $document->id]);

        $session = UploadSession::factory()->create();
        $this->assertDatabaseHas('upload_sessions', ['id' => $session->id]);
    }

    public function test_document_factory_states(): void
    {
        $defaultDoc = Document::factory()->create();
        $this->assertSame(DocumentStatus::Committed, $defaultDoc->status);
        $this->assertFalse($defaultDoc->is_latest);
        $this->assertNull($defaultDoc->latest_marker);

        $processingDoc = Document::factory()->processing()->create();
        $this->assertSame(DocumentStatus::Processing, $processingDoc->status);

        $rejectedDoc = Document::factory()->rejected()->create();
        $this->assertSame(DocumentStatus::Rejected, $rejectedDoc->status);

        $supersededDoc = Document::factory()->superseded()->create();
        $this->assertFalse($supersededDoc->is_latest);
        $this->assertNull($supersededDoc->latest_marker);
    }

    public function test_document_factory_latest_matches_document_attacher_single_slot(): void
    {
        Storage::fake('s3');
        $service = app(DocumentService::class);

        $type = DocumentType::factory()->create([
            'allows_multiple' => false,
            'requires_versioning' => true,
        ]);
        $owner = TestModel::create(['name' => 'Owner']);

        $serviceDoc = $service->upload(
            UploadedFile::fake()->createWithContent('file.pdf', "%PDF-1.4\n%test\n"),
            $type,
            $owner
        );

        $factoryDoc = Document::factory()->for($type)->latest()->create([
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => 'custom-owner-id',
        ]);

        $this->assertTrue($serviceDoc->is_latest);
        $this->assertSame($type->id, $serviceDoc->latest_marker);

        $this->assertTrue($factoryDoc->is_latest);
        $this->assertSame($type->id, $factoryDoc->latest_marker);
    }

    public function test_document_factory_latest_matches_document_attacher_multi_slot(): void
    {
        Storage::fake('s3');
        $service = app(DocumentService::class);

        $type = DocumentType::factory()->create([
            'allows_multiple' => true,
            'requires_versioning' => true,
        ]);
        $owner = TestModel::create(['name' => 'Owner']);

        $serviceDoc = $service->upload(
            UploadedFile::fake()->createWithContent('file.pdf', "%PDF-1.4\n%test\n"),
            $type,
            $owner
        );

        $factoryDoc = Document::factory()->for($type)->latest()->create([
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ]);

        $this->assertTrue($serviceDoc->is_latest);
        $this->assertSame($serviceDoc->document_group_id, $serviceDoc->latest_marker);

        $this->assertTrue($factoryDoc->is_latest);
        $this->assertSame($factoryDoc->document_group_id, $factoryDoc->latest_marker);
    }

    public function test_integrity_inspector_reports_nothing_for_factory_built_latest_document(): void
    {
        $singleType = DocumentType::factory()->create(['allows_multiple' => false]);
        $multiType = DocumentType::factory()->create(['allows_multiple' => true]);

        Document::factory()->for($singleType)->latest()->create();
        Document::factory()->for($multiType)->latest()->create();

        $inspector = app(IntegrityInspector::class);
        $report = $inspector->inspect();

        $this->assertSame([], $report->duplicateSlots);
        $this->assertSame([], $report->duplicateStorageFiles);
        $this->assertSame([], $report->documentsMissingStorageFile);
        $this->assertSame([], $report->documentsOutsideKeyType);
    }

    public function test_upload_session_factory_states_and_hashes(): void
    {
        $initiated = UploadSession::factory()->initiated()->create();
        $this->assertSame(UploadSessionStatus::Initiated, $initiated->status);

        $uploaded = UploadSession::factory()->uploaded()->create();
        $this->assertSame(UploadSessionStatus::Uploaded, $uploaded->status);
        $this->assertNotNull($uploaded->object_hash);
        $this->assertSame(64, strlen((string) $uploaded->object_hash));
        $this->assertTrue(ctype_xdigit((string) $uploaded->object_hash));

        $attached = UploadSession::factory()->attached()->create();
        $this->assertSame(UploadSessionStatus::Attached, $attached->status);
        $this->assertNotNull($attached->storage_file_id);
        $this->assertNotNull($attached->owner_type);
        $this->assertNotNull($attached->owner_id);

        $expired = UploadSession::factory()->expired()->create();
        $this->assertSame(UploadSessionStatus::Expired, $expired->status);
        $this->assertTrue($expired->expires_at->isPast());

        $aborted = UploadSession::factory()->aborted()->create();
        $this->assertSame(UploadSessionStatus::Aborted, $aborted->status);
    }

    public function test_sizes_and_hashes_are_consistent(): void
    {
        $doc = Document::factory()->create();

        $this->assertSame(64, strlen($doc->storageFileOrFail()->file_hash));
        $this->assertTrue(ctype_xdigit($doc->storageFileOrFail()->file_hash));
        $this->assertSame($doc->storageFileOrFail()->size_bytes, $doc->size);
        $this->assertSame($doc->storageFileOrFail()->mime_type, $doc->mime);
        $this->assertSame($doc->storageFileOrFail()->size_bytes, $doc->metadata['size'] ?? null);
    }
}
