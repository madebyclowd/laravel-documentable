<?php

namespace MadeByClowd\Documentable\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Relations\DocumentsMorphMany;

trait Documentable
{
    public function documents(): MorphMany
    {
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
