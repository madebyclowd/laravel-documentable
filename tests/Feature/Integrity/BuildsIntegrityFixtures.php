<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;

/**
 * Builds rows a healthy install can never produce, so the integrity tools have violations to find. Every row
 * is written straight to the tables: going through the service would refuse to create them.
 */
trait BuildsIntegrityFixtures
{
    /**
     * A healthy install cannot hold two stored files for one `(file_hash, disk)`; an install being upgraded can,
     * so tests that seed that state take the index away first.
     */
    protected function dropDedupIndex(): void
    {
        Schema::table('storage_files', fn ($table) => $table->dropUnique('storage_files_hash_disk_unique'));
    }

    /**
     * Leaves `$document` pointing at a stored file that no longer exists. Engines that enforce the foreign key
     * (MySQL, PostgreSQL) cannot reach that state at all, so there the test has nothing to check.
     */
    protected function orphanTheStorageFileOf(Document $document): void
    {
        Schema::disableForeignKeyConstraints();
        $document->storageFile->delete();
        Schema::enableForeignKeyConstraints();

        if (! Document::withTrashed()->whereKey($document->id)->exists()) {
            $this->markTestSkipped('This database enforces the documents.storage_file_id foreign key, so a document cannot outlive its stored file.');
        }
    }

    protected function singleSlotType(string $code = 'PASSPORT', bool $versioned = false): DocumentType
    {
        return DocumentType::create(['code' => $code, 'name' => $code, 'max_size_mb' => 5, 'disk' => 'test_disk', 'path_prefix' => strtolower($code), 'allows_multiple' => false, 'requires_versioning' => $versioned]);
    }

    protected function multiSlotType(string $code = 'ATTACHMENT'): DocumentType
    {
        return DocumentType::create(['code' => $code, 'name' => $code, 'max_size_mb' => 5, 'disk' => 'test_disk', 'path_prefix' => strtolower($code), 'allows_multiple' => true]);
    }

    protected function owner(): TestModel
    {
        return TestModel::create(['name' => 'owner']);
    }

    protected function storageFile(?string $hash = null, string $disk = 'test_disk', ?string $path = null, ?string $createdAt = null): StorageFile
    {
        $fileHash = $hash ?? hash('sha256', Str::random());
        $contentHash = strlen($fileHash) === 64 && ctype_xdigit($fileHash) ? $fileHash : hash('sha256', $fileHash);

        $file = StorageFile::create([
            'file_hash' => $fileHash,
            'content_hash' => $contentHash,
            'disk' => $disk,
            'path' => $path ?? 'blobs/'.Str::uuid(),
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
        ]);

        if ($createdAt !== null) {
            StorageFile::whereKey($file->id)->update(['created_at' => $createdAt]);
        }

        return $file->refresh();
    }

    protected function latestDocument(DocumentType $type, TestModel $owner, ?StorageFile $file = null, int $version = 1, ?string $createdAt = null, ?string $marker = null): Document
    {
        $groupId = (string) Str::uuid();

        $document = Document::create([
            'storage_file_id' => ($file ?? $this->storageFile())->id,
            'document_type_id' => $type->id,
            'document_group_id' => $groupId,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
            'client_filename' => 'file.pdf',
            'version' => $version,
            'is_latest' => true,
            'latest_marker' => $marker ?? $groupId,
        ]);

        if ($createdAt !== null) {
            Document::whereKey($document->id)->update(['created_at' => $createdAt]);
        }

        return $document->refresh();
    }
}
