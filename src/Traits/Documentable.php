<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Relations\DocumentsMorphMany;

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
}
