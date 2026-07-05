<?php

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;

class TenantScopedDedupScope implements ResolvesDedupScope
{
    public function scopeKey(string $hash, ?Model $documentable): string
    {
        $tenant = $documentable?->getKey() ?? 'none';

        return "{$tenant}:{$hash}";
    }
}
