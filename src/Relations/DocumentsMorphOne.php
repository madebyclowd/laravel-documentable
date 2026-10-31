<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use MadeByClowd\Documentable\Models\Document;

/**
 * `documents.documentable_id` is a string column so one table can serve owners with int, uuid and ulid keys.
 * This relation sends the owner key as a string everywhere it meets `documentable_id`, matching `DocumentsMorphMany`.
 *
 * @internal Returned by `Documentable::documentOfType()`; do not instantiate or extend it.
 *
 * @extends MorphOne<Document, Model>
 */
final class DocumentsMorphOne extends MorphOne
{
    /**
     * The owner key as a string: used for the lazy-load constraint and for the foreign key of documents made
     * through the relation, so both match the column's type.
     */
    public function getParentKey()
    {
        $key = parent::getParentKey();

        return $key === null ? null : (string) $key;
    }

    /** {@inheritDoc} */
    public function addEagerConstraints(array $models)
    {
        $this->getRelationQuery()
            ->whereIn($this->foreignKey, array_map('strval', $this->getKeys($models, $this->localKey)))
            ->where($this->morphType, $this->morphClass);
    }
}
