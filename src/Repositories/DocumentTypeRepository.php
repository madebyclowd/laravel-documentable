<?php

namespace MadeByClowd\Documentable\Repositories;

use MadeByClowd\Documentable\Models\DocumentType;

class DocumentTypeRepository
{
    /**
     * Upsert a code-first DocumentType definition by `code`. Revives a previously
     * deactivated (soft-deleted) type if it reappears in config, rather than
     * creating a duplicate row — `code` is the stable identity, not the uuid pk.
     */
    public function updateOrCreate(string $code, array $attributes): DocumentType
    {
        $type = DocumentType::withTrashed()->firstWhere('code', $code);

        if (! $type) {
            return DocumentType::create(array_merge($attributes, ['code' => $code]));
        }

        if ($type->trashed()) {
            $type->restore();
        }

        $type->fill($attributes);
        $type->save();

        return $type;
    }

    /**
     * Deactivate (soft-delete, never hard-delete — preserves FK integrity for
     * Document rows still referencing a retired type) every DocumentType whose
     * `code` is missing from the given list of currently config-defined codes.
     */
    public function deactivateMissing(array $codes): int
    {
        return DocumentType::whereNotIn('code', $codes)->delete();
    }
}
