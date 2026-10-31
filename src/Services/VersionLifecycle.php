<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Events\DocumentVersionPromoted;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Encapsulates version lifecycle operations: setting and clearing the deterministic latest marker,
 * promoting candidates, restoring soft-deleted versions, and maintaining the single latest invariant.
 *
 * @internal
 */
final class VersionLifecycle
{
    /**
     * Set the deterministic latest flag and marker on a document for its type and group.
     */
    public function setLatest(Document $document, DocumentType $type): void
    {
        $marker = $type->allows_multiple ? $document->document_group_id : $type->id;

        $document->update([
            'is_latest' => true,
            'latest_marker' => $marker,
        ]);
    }

    /**
     * Clear the deterministic latest flag and marker on a document.
     */
    public function clearLatest(Document $document): void
    {
        $document->update([
            'is_latest' => false,
            'latest_marker' => null,
        ]);
    }

    /**
     * Find the highest remaining non-trashed version in the group eligible for promotion.
     * Only committed documents are eligible.
     */
    public function findPromotionCandidate(string $groupId, ?string $excludeId = null): ?Document
    {
        $query = Document::where('document_group_id', $groupId)
            ->where('status', DocumentStatus::Committed->value);

        if ($excludeId !== null) {
            $query->whereKeyNot($excludeId);
        }

        return $query->orderByDesc('version')->first();
    }

    /**
     * Restore a soft-deleted document. It becomes the latest only when its group has no latest, no higher version
     * exists and the single-slot marker is free; otherwise it comes back as an older version.
     */
    public function restore(Document $document): Document
    {
        if (! $document->trashed()) {
            return $document;
        }

        return DB::transaction(function () use ($document) {
            $this->lockGroup($document);

            $document->refresh();

            if (! $document->trashed()) {
                return $document;
            }

            $document->restore();
            $document->update(['deleted_by' => null]);

            $type = $document->documentTypeOrFail();

            if ($this->canTakeSlot($document, $type)) {
                $this->setLatest($document, $type);
            } else {
                $this->clearLatest($document);
            }

            return $document;
        });
    }

    /**
     * Make a specific non-latest, non-trashed version the latest of its group. The current latest is demoted first,
     * in the same transaction and under the group lock.
     *
     * @throws InvalidArgumentException
     */
    public function promote(Document $document): Document
    {
        return DB::transaction(function () use ($document) {
            $this->lockGroup($document);

            $document->refresh();

            if ($document->trashed()) {
                throw new InvalidArgumentException("Cannot promote trashed document {$document->id}.");
            }

            if ($document->is_latest) {
                return $document;
            }

            $type = $document->documentTypeOrFail();
            $previous = $this->currentLatest($document, $type);

            if ($previous !== null) {
                $this->clearLatest($previous);
            }

            $this->setLatest($document, $type);

            Event::dispatch(new DocumentVersionPromoted($previous, $document));

            return $document;
        });
    }

    private function lockGroup(Document $document): void
    {
        Document::withTrashed()->where('document_group_id', $document->document_group_id)->lockForUpdate()->get();
    }

    /**
     * The document holding the type's latest slot for this owner (single-slot types) or group (multi-slot types).
     */
    private function currentLatest(Document $document, DocumentType $type): ?Document
    {
        $query = Document::where('is_latest', true)->whereKeyNot($document->id);

        return $type->allows_multiple
            ? $query->where('document_group_id', $document->document_group_id)->first()
            : $query->where('documentable_type', $document->documentable_type)
                ->where('documentable_id', $document->documentable_id)
                ->where('document_type_id', $type->id)
                ->first();
    }

    private function canTakeSlot(Document $document, DocumentType $type): bool
    {
        $higherVersionExists = Document::where('document_group_id', $document->document_group_id)
            ->where('version', '>', $document->version)
            ->exists();

        return ! $higherVersionExists && $this->currentLatest($document, $type) === null;
    }
}
