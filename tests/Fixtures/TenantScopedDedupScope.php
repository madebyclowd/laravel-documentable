<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Models\DocumentType;

class TenantScopedDedupScope implements ResolvesDedupScope
{
    public function scopeKey(string $hash, DocumentType $type, ?Authenticatable $user, ?Model $owner): string
    {
        $tenant = $owner?->getKey() ?? 'none';

        return "{$tenant}:{$hash}";
    }
}
