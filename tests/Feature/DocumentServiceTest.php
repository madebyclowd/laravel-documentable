<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class DocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'GENERIC',
            'name' => 'Generic',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'uploads',
        ], $overrides));
    }

    public function test_uploading_identical_bytes_dedups_to_one_storage_file(): void
    {
        $type = $this->makeType();
        $ownerA = TestModel::create(['name' => 'a']);
        $ownerB = TestModel::create(['name' => 'b']);

        $bytes = 'identical-bytes-for-dedup-test';
        $fileA = UploadedFile::fake()->createWithContent('a.txt', $bytes);
        $fileB = UploadedFile::fake()->createWithContent('b.txt', $bytes);

        $this->service->upload($fileA, $type, $ownerA);
        $this->service->upload($fileB, $type, $ownerB);

        $this->assertSame(1, StorageFile::count());
        $this->assertSame(2, Document::count());
    }

    public function test_purge_only_deletes_physical_file_when_no_other_document_references_it(): void
    {
        $type = $this->makeType();
        $ownerA = TestModel::create(['name' => 'a']);
        $ownerB = TestModel::create(['name' => 'b']);

        $bytes = 'shared-bytes-for-purge-test';
        $docA = $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', $bytes), $type, $ownerA);
        $docB = $this->service->upload(UploadedFile::fake()->createWithContent('b.txt', $bytes), $type, $ownerB);

        $storagePath = $docA->storageFile->path;
        $this->assertTrue(Storage::disk('s3')->exists($storagePath));

        $this->service->purge($docA->fresh());

        // Still referenced by docB — physical file and StorageFile row survive.
        $this->assertTrue(Storage::disk('s3')->exists($storagePath));
        $this->assertSame(1, StorageFile::count());

        $this->service->purge($docB->fresh());

        // No longer referenced by anything — both are gone.
        $this->assertFalse(Storage::disk('s3')->exists($storagePath));
        $this->assertSame(0, StorageFile::count());
    }

    public function test_upload_rejects_file_exceeding_max_size(): void
    {
        $type = $this->makeType(['max_size_mb' => 1]);
        $owner = TestModel::create(['name' => 'a']);

        $file = UploadedFile::fake()->create('big.pdf', 2000); // 2000KB > 1MB limit

        $this->expectException(ValidationException::class);

        $this->service->upload($file, $type, $owner);
    }

    public function test_upload_rejects_disallowed_mime_type(): void
    {
        $type = $this->makeType(['allowed_mimes' => ['image/png']]);
        $owner = TestModel::create(['name' => 'a']);

        $file = UploadedFile::fake()->create('file.pdf', 10, 'application/pdf');

        $this->expectException(ValidationException::class);

        $this->service->upload($file, $type, $owner);
    }

    public function test_uploads_to_same_owner_and_type_replace_previous_when_versioning_disabled(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'a']);

        $this->service->upload(UploadedFile::fake()->createWithContent('v1.txt', 'v1'), $type, $owner);
        $this->service->upload(UploadedFile::fake()->createWithContent('v2.txt', 'v2'), $type, $owner);

        $this->assertSame(1, Document::count());
        $this->assertSame('v2.txt', Document::first()->client_filename);
    }

    public function test_uploads_to_same_owner_and_type_version_when_versioning_enabled(): void
    {
        $type = $this->makeType(['requires_versioning' => true]);
        $owner = TestModel::create(['name' => 'a']);

        $this->service->upload(UploadedFile::fake()->createWithContent('v1.txt', 'v1'), $type, $owner);
        $latest = $this->service->upload(UploadedFile::fake()->createWithContent('v2.txt', 'v2'), $type, $owner);

        $this->assertSame(2, Document::withTrashed()->count());
        $this->assertSame(2, $latest->version);
        $this->assertTrue($latest->is_latest);
    }
}
