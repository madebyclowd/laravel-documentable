<?php

namespace MadeByClowd\Documentable\Traits;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use MadeByClowd\Documentable\Models\Document;

trait Documentable
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
