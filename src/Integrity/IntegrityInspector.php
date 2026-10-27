<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Integrity;

use Illuminate\Support\Str;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * Reads the tables and reports every row that would break a database invariant: the single-slot rule, the
 * unique dedup key and the document to stored file link. It never writes.
 *
 * Soft-deleted documents are included wherever the unique index would see them, because an index does not
 * know about `deleted_at`.
 *
 * @internal
 */
final class IntegrityInspector
{
    public function __construct(private readonly DocumentableConfig $config) {}

    public function inspect(): IntegrityReport
    {
        return new IntegrityReport(
            duplicateSlots: $this->duplicateSlots(),
            duplicateStorageFiles: $this->duplicateStorageFiles(),
            documentsMissingStorageFile: $this->documentsMissingStorageFile(),
            documentsOutsideKeyType: $this->documentsOutsideKeyType(),
            keyType: $this->config->keyType,
            openUploadSessions: UploadSession::query()->whereIn('status', array_map(
                fn (UploadSessionStatus $status) => $status->value,
                array_filter(UploadSessionStatus::cases(), fn (UploadSessionStatus $status) => $status->isOpen()),
            ))->count(),
            ownerlessDocuments: Document::query()->whereNull('documentable_id')->count(),
        );
    }

    /**
     * @return list<DuplicateLatestSlot>
     */
    private function duplicateSlots(): array
    {
        $slots = Document::withTrashed()
            ->select('documentable_type', 'documentable_id', 'document_type_id')
            ->whereNotNull('latest_marker')
            ->whereNotNull('documentable_type')
            ->whereNotNull('documentable_id')
            ->whereIn('document_type_id', DocumentType::withTrashed()->where('allows_multiple', false)->select('id'))
            ->groupBy('documentable_type', 'documentable_id', 'document_type_id')
            ->havingRaw('count(*) > 1')
            ->orderBy('documentable_type')
            ->orderBy('documentable_id')
            ->orderBy('document_type_id')
            ->get();

        return array_values($slots->map(fn (Document $slot) => new DuplicateLatestSlot(
            ownerType: (string) $slot->documentable_type,
            ownerId: (string) $slot->documentable_id,
            documentTypeId: $slot->document_type_id,
            documentIds: $this->candidateIds($slot),
        ))->all());
    }

    /**
     * @return non-empty-list<string>
     */
    private function candidateIds(Document $slot): array
    {
        /** @var non-empty-list<string> */
        return Document::withTrashed()
            ->where('documentable_type', $slot->documentable_type)
            ->where('documentable_id', $slot->documentable_id)
            ->where('document_type_id', $slot->document_type_id)
            ->whereNotNull('latest_marker')
            ->orderByDesc('version')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('id')
            ->map(fn (mixed $id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<DuplicateStorageFile>
     */
    private function duplicateStorageFiles(): array
    {
        $pairs = StorageFile::query()
            ->select('file_hash', 'disk')
            ->groupBy('file_hash', 'disk')
            ->havingRaw('count(*) > 1')
            ->orderBy('file_hash')
            ->orderBy('disk')
            ->get();

        return array_values($pairs->map(fn (StorageFile $pair) => new DuplicateStorageFile(
            fileHash: $pair->file_hash,
            disk: $pair->disk,
            storageFileIds: $this->storageFileIdsOf($pair),
        ))->all());
    }

    /**
     * @return non-empty-list<string>
     */
    private function storageFileIdsOf(StorageFile $pair): array
    {
        /** @var non-empty-list<string> */
        return StorageFile::query()
            ->where('file_hash', $pair->file_hash)
            ->where('disk', $pair->disk)
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id) => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function documentsMissingStorageFile(): array
    {
        return array_values(Document::withTrashed()
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('storage_files')->whereColumn('storage_files.id', 'documents.storage_file_id'))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id) => (string) $id)
            ->all());
    }

    /**
     * Documents whose owner key cannot be stored in a column of the configured `key_type`. Nothing is wrong
     * with them while the column is a string; they block the conversion to a typed column.
     *
     * @return list<string>
     */
    private function documentsOutsideKeyType(): array
    {
        $fits = match ($this->config->keyType) {
            'int' => ctype_digit(...),
            'uuid' => Str::isUuid(...),
            'ulid' => Str::isUlid(...),
            default => null,
        };

        if ($fits === null) {
            return [];
        }

        $misfits = [];

        Document::withTrashed()
            ->select('id', 'documentable_id')
            ->whereNotNull('documentable_id')
            ->chunkById(1000, function ($documents) use ($fits, &$misfits) {
                foreach ($documents as $document) {
                    if (! $fits((string) $document->documentable_id)) {
                        $misfits[] = $document->id;
                    }
                }
            });

        return $misfits;
    }
}
