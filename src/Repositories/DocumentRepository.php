<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Repositories;

use Illuminate\Database\Eloquent\Collection;
use MadeByClowd\Documentable\Models\Document;

class DocumentRepository
{
    public function create(array $data): Document
    {
        return Document::create($data);
    }

    /**
     * Find the latest document for an owner+type. Pass $documentGroupId to scope
     * to one specific group/slot (needed once a type `allows_multiple`); omit it
     * for single-slot types where at most one group can ever exist.
     *
     * With `$lock` the row is read `FOR UPDATE`, so two uploads that version the same document take turns. It
     * only holds inside a transaction.
     */
    public function findLatest(string $documentableType, string $documentableId, string $documentTypeId, ?string $documentGroupId = null, bool $lock = false): ?Document
    {
        $query = Document::where('documentable_type', $documentableType)
            ->where('documentable_id', $documentableId)
            ->where('document_type_id', $documentTypeId)
            ->where('is_latest', true);

        if ($documentGroupId !== null) {
            $query->where('document_group_id', $documentGroupId);
        }

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Whether this owner already has the group for this type, soft-deleted versions included. A client-supplied
     * group id is only meaningful if it names a slot the owner really holds.
     */
    public function groupExistsForOwner(string $documentableType, string $documentableId, string $documentTypeId, string $documentGroupId): bool
    {
        return Document::withTrashed()
            ->where('documentable_type', $documentableType)
            ->where('documentable_id', $documentableId)
            ->where('document_type_id', $documentTypeId)
            ->where('document_group_id', $documentGroupId)
            ->exists();
    }

    /**
     * One row per active group for this owner+type — the "list current
     * documents" query that a single findLatest() couldn't express before
     * document_group_id existed.
     *
     * @return Collection<int, Document>
     */
    public function findAllLatestForOwner(string $documentableType, string $documentableId, string $documentTypeId): Collection
    {
        return Document::where('documentable_type', $documentableType)
            ->where('documentable_id', $documentableId)
            ->where('document_type_id', $documentTypeId)
            ->whereNotNull('latest_marker')
            ->get();
    }

    /**
     * All latest-per-group rows for an owner, across every document type unless
     * $documentTypeId narrows it — the cross-type sibling of findAllLatestForOwner()
     * (used by the listing endpoint). storageFile is eager-loaded so callers don't
     * hand back a document without its stored file details.
     *
     * @return Collection<int, Document>
     */
    public function findAllLatestForOwnerGrouped(string $documentableType, string $documentableId, ?string $documentTypeId = null): Collection
    {
        $query = Document::where('documentable_type', $documentableType)
            ->where('documentable_id', $documentableId)
            ->whereNotNull('latest_marker')
            ->with('storageFile');

        if ($documentTypeId !== null) {
            $query->where('document_type_id', $documentTypeId);
        }

        return $query->get();
    }

    /**
     * Full version chain for one group, oldest first.
     *
     * @return Collection<int, Document>
     */
    public function findVersionHistory(string $documentGroupId, bool $withTrashed = false): Collection
    {
        $query = Document::where('document_group_id', $documentGroupId)->orderBy('version');

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->get();
    }

    public function findById(string $id): Document
    {
        return Document::findOrFail($id);
    }

    public function delete(Document $document): bool
    {
        return (bool) $document->delete();
    }

    public function forceDelete(Document $document): bool
    {
        return (bool) $document->forceDelete();
    }
}
