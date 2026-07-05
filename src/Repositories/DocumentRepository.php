<?php

namespace MadeByClowd\Documentable\Repositories;

use MadeByClowd\Documentable\Models\Document;

class DocumentRepository
{
    public function create(array $data): Document
    {
        return Document::create($data);
    }

    /**
     * Single-slot lookup only for phase 1 — no document_group_id yet (phase 2).
     */
    public function findLatest(string $documentableType, string $documentableId, string $documentTypeId): ?Document
    {
        return Document::where('documentable_type', $documentableType)
            ->where('documentable_id', $documentableId)
            ->where('document_type_id', $documentTypeId)
            ->where('is_latest', true)
            ->first();
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
