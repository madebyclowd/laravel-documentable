<?php

namespace MadeByClowd\Documentable\Defaults;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;

class HashOnlyDedupScope implements ResolvesDedupScope
{
    public function scopeKey(string $hash, ?Model $documentable): string
    {
        return $hash;
    }
}
