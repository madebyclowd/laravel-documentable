<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Relations\DocumentsMorphMany;
use MadeByClowd\Documentable\Relations\DocumentsMorphOne;

trait Documentable
{
    /**
     * With a typed `key_type` the owner key and `documentable_id` share a type, so Laravel's stock relation is
     * correct on every engine. Only the `string` column, which owners of any key type share, needs the relation
     * that sends the key as a string.
     */
    public function documents(): MorphMany
    {
        if (app(DocumentableConfig::class)->keyType !== 'string') {
            return $this->morphMany(Document::class, 'documentable');
        }

        $instance = $this->newRelatedInstance(Document::class);

        return new DocumentsMorphMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn('documentable_type'),
            $instance->qualifyColumn('documentable_id'),
            $this->getKeyName()
        );
    }

    /**
     * The latest document of the given type code for this owner.
     */
    public function documentOfType(string $code): MorphOne
    {
        if (app(DocumentableConfig::class)->keyType !== 'string') {
            $relation = $this->morphOne(Document::class, 'documentable');
        } else {
            $instance = $this->newRelatedInstance(Document::class);

            $relation = new DocumentsMorphOne(
                $instance->newQuery(),
                $this,
                $instance->qualifyColumn('documentable_type'),
                $instance->qualifyColumn('documentable_id'),
                $this->getKeyName()
            );
        }

        $relation->latestVersion()->ofType($code);

        return $relation;
    }

    /**
     * All documents of the given type code for this owner.
     */
    public function documentsOfType(string $code): MorphMany
    {
        $relation = $this->documents();
        $relation->ofType($code);

        return $relation;
    }

    /**
     * Eager-load only the latest document of the given type into `documents`, in one extra query for any number of
     * owners: `Owner::withDocumentOfType('INVOICE')->get()`.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithDocumentOfType(Builder $query, string $code): void
    {
        $query->with(['documents' => fn ($documents) => $documents->latestVersion()->ofType($code)]);
    }
}
