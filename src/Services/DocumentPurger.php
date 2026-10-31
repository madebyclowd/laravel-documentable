<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Enums\OnDeleteLatest;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentPurged;
use MadeByClowd\Documentable\Events\DocumentVersionPromoted;
use MadeByClowd\Documentable\Exceptions\CannotDeleteLatestVersion;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;

/**
 * Removes documents and the stored blobs behind them: soft delete, permanent purge, and the shared-blob check
 * that keeps a deduplicated file alive while any document still points at it.
 *
 * @internal
 */
final class DocumentPurger
{
    private readonly VersionLifecycle $lifecycle;

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly StorageFileRepository $storageFileRepository,
        private readonly ActorResolver $actor,
        ?VersionLifecycle $lifecycle = null,
    ) {
        $this->lifecycle = $lifecycle ?? app(VersionLifecycle::class);
    }

    /**
     * Soft-delete a document. When it is the latest of its group, the type's `on_delete_latest` policy decides what
     * happens to the slot: `promote` hands it to the highest remaining committed version, `none` leaves the group
     * without a latest, `block` refuses while older versions exist. Either way the marker is cleared in the same
     * transaction, otherwise the soft-deleted row would keep occupying the unique slot.
     *
     * @throws CannotDeleteLatestVersion when the policy is block and older non-trashed versions exist
     */
    public function delete(Document $document): bool
    {
        return DB::transaction(function () use ($document) {
            Document::where('document_group_id', $document->document_group_id)->lockForUpdate()->get();

            $document->refresh();

            $wasLatest = $document->is_latest;
            $type = $document->documentTypeOrFail();
            $policy = $type->on_delete_latest;

            if ($wasLatest && $policy === OnDeleteLatest::Block && $this->hasOtherVersions($document)) {
                throw new CannotDeleteLatestVersion($document);
            }

            $this->lifecycle->clearLatest($document);
            $document->update(['deleted_by' => $this->actor->idForAudit()]);

            $deleted = $this->documentRepository->delete($document);

            if (! $deleted) {
                return false;
            }

            Event::dispatch(new DocumentDeleted($document));

            if ($wasLatest && $policy === OnDeleteLatest::Promote) {
                $this->promoteNext($document, $type);
            }

            return true;
        });
    }

    private function hasOtherVersions(Document $document): bool
    {
        return Document::where('document_group_id', $document->document_group_id)
            ->whereKeyNot($document->id)
            ->exists();
    }

    private function promoteNext(Document $deleted, DocumentType $type): void
    {
        $candidate = $this->lifecycle->findPromotionCandidate($deleted->document_group_id, $deleted->id);

        if ($candidate === null) {
            return;
        }

        $this->lifecycle->setLatest($candidate, $type);
        Event::dispatch(new DocumentVersionPromoted($deleted, $candidate));
    }

    /**
     * Soft-delete every active version of a group in one transaction without promotion.
     */
    public function deleteGroup(string $groupId): int
    {
        if (! Str::isUuid($groupId)) {
            return 0;
        }

        return DB::transaction(function () use ($groupId) {
            $documents = Document::where('document_group_id', $groupId)
                ->lockForUpdate()
                ->get();

            $count = 0;
            $actorId = $this->actor->idForAudit();

            foreach ($documents as $document) {
                $this->lifecycle->clearLatest($document);
                $document->update(['deleted_by' => $actorId]);

                if ($this->documentRepository->delete($document)) {
                    $count++;
                    Event::dispatch(new DocumentDeleted($document));
                }
            }

            return $count;
        });
    }

    /**
     * Permanently delete a document, then its stored blob when no other document references it.
     *
     * The document row is gone once this returns, whatever the result. `false` means the document could not be
     * deleted, or that it was but the blob could not: the `StorageFile` row is kept as the record of the
     * orphaned object, an error is logged, and `documents:clean-orphaned --storage-files` retries it. Disks
     * configured with `throw => false` report a failed delete as `false` rather than an exception, which is
     * why the result is checked rather than assumed.
     */
    public function purge(Document $document): bool
    {
        $storageFile = $document->storageFile;

        $deleted = $this->documentRepository->forceDelete($document);

        if (! $deleted) {
            return false;
        }

        $storageFileAlsoDeleted = false;

        if ($storageFile !== null) {
            $storageFileAlsoDeleted = $this->removeStorageFileIfUnreferenced($storageFile);
        }

        Event::dispatch(new DocumentPurged($document, $storageFileAlsoDeleted));

        return $storageFile === null || $storageFileAlsoDeleted || $this->isStorageFileReferenced($storageFile);
    }

    /**
     * Delete a stored blob and its `StorageFile` row if no document, trashed ones included, references it.
     * The check repeats under a row lock because a deduplicated upload may have attached a document to the
     * blob since the caller looked. Returns true when the blob and row are gone, false when the blob could
     * not be deleted (the row is kept so the next sweep can retry) or when the file is still referenced.
     */
    public function removeStorageFileIfUnreferenced(StorageFile $storageFile): bool
    {
        return DB::transaction(function () use ($storageFile) {
            $locked = StorageFile::whereKey($storageFile->id)->lockForUpdate()->first();

            if ($locked === null) {
                return true;
            }

            if ($this->isStorageFileReferenced($locked)) {
                return false;
            }

            if (! $this->deleteBlob($locked->disk, $locked->path)) {
                return false;
            }

            return $this->storageFileRepository->delete($locked);
        });
    }

    private function isStorageFileReferenced(StorageFile $storageFile): bool
    {
        // An open session holds the file for a document it is about to create (a handshake session links a file
        // other documents may all have dropped), so it keeps the file alive like a document does.
        return Document::withTrashed()->where('storage_file_id', $storageFile->id)->exists()
            || UploadSession::where('storage_file_id', $storageFile->id)
                ->whereIn('status', [UploadSessionStatus::Initiated, UploadSessionStatus::Uploaded])
                ->exists();
    }

    /**
     * True when the object no longer exists on the disk afterwards, whether it was deleted now or was already
     * gone. A failed delete is logged with the disk and path, because the row that remembers it is the only
     * trace.
     */
    private function deleteBlob(string $disk, string $path): bool
    {
        try {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->exists($path)) {
                return true;
            }

            if ($filesystem->delete($path) !== false) {
                return true;
            }

            $reason = 'the disk reported the delete as failed';
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        Log::error("Documentable could not delete [{$path}] from disk [{$disk}]: {$reason}. The storage file row is kept so documents:clean-orphaned --storage-files can retry.");

        return false;
    }
}
