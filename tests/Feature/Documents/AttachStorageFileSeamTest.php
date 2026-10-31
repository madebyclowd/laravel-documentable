<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;

class AttachStorageFileSeamTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected DocumentType $singleType;

    protected DocumentType $multiType;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        $this->service = app(DocumentService::class);

        $this->singleType = DocumentType::create([
            'code' => 'AVATAR',
            'name' => 'Avatar',
            'max_size_mb' => 5,
            'disk' => 's3',
            'path_prefix' => 'avatars',
            'allows_multiple' => false,
            'requires_versioning' => true,
        ]);

        $this->multiType = DocumentType::create([
            'code' => 'ATTACHMENT',
            'name' => 'Attachment',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'attachments',
            'allows_multiple' => true,
            'requires_versioning' => true,
        ]);
    }

    public function test_attaches_existing_storage_file_to_second_owner(): void
    {
        $owner1 = TestModel::create(['name' => 'User 1']);
        $owner2 = TestModel::create(['name' => 'User 2']);

        $doc1 = $this->service->upload(
            UploadedFile::fake()->createWithContent('shared.pdf', 'shared content'),
            $this->multiType,
            $owner1
        );

        $storageFile = $doc1->storageFileOrFail();

        $doc2 = $this->service->attachStorageFile(
            $storageFile,
            $this->multiType,
            $owner2,
            'user2_file.pdf'
        );

        $this->assertSame($storageFile->id, $doc2->storage_file_id);
        $this->assertSame($owner2->getMorphClass(), $doc2->documentable_type);
        $this->assertSame((string) $owner2->getKey(), (string) $doc2->documentable_id);
        $this->assertSame('user2_file.pdf', $doc2->client_filename);
        $this->assertSame(DocumentStatus::Committed, $doc2->status);
        $this->assertTrue($doc2->is_latest);
    }

    public function test_honours_attach_options_metadata_and_group_id(): void
    {
        $owner = TestModel::create(['name' => 'Owner']);

        $doc1 = $this->service->upload(
            UploadedFile::fake()->createWithContent('version1.pdf', 'v1 content'),
            $this->multiType,
            $owner
        );

        $storageFile = $doc1->storageFileOrFail();
        $groupId = $doc1->document_group_id;

        $options = new AttachOptions(
            metadata: ['department' => 'finance', 'tag' => 'q3'],
            documentGroupId: $groupId
        );

        $doc2 = $this->service->attachStorageFile(
            $storageFile,
            $this->multiType,
            $owner,
            'version2.pdf',
            $options
        );

        $this->assertSame($groupId, $doc2->document_group_id);
        $this->assertSame(2, $doc2->version);
        $this->assertTrue($doc2->is_latest);
        $this->assertSame('finance', $doc2->metadata['department'] ?? null);
        $this->assertSame('q3', $doc2->metadata['tag'] ?? null);

        $doc1->refresh();
        $this->assertFalse($doc1->is_latest);
        $this->assertNull($doc1->latest_marker);
    }

    public function test_single_slot_behaviour_matches_attach(): void
    {
        $owner = TestModel::create(['name' => 'Avatar Owner']);

        $doc1 = $this->service->upload(
            UploadedFile::fake()->createWithContent('avatar1.png', 'avatar bytes 1'),
            $this->singleType,
            $owner
        );

        $this->assertTrue($doc1->is_latest);
        $this->assertSame($this->singleType->id, $doc1->latest_marker);
        $this->assertSame(1, $doc1->version);

        $doc2 = $this->service->attachStorageFile(
            $doc1->storageFileOrFail(),
            $this->singleType,
            $owner,
            'avatar2.png'
        );

        $this->assertTrue($doc2->is_latest);
        $this->assertSame($this->singleType->id, $doc2->latest_marker);
        $this->assertSame(2, $doc2->version);
        $this->assertSame($doc1->document_group_id, $doc2->document_group_id);

        $doc1->refresh();
        $this->assertFalse($doc1->is_latest);
        $this->assertNull($doc1->latest_marker);
    }
}
