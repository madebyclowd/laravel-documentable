<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Deduplication scope partitioned strictly by document type and owner model instance.
 *
 * Each owner model receives its own `StorageFile` rows, eliminating any cross-owner deduplication.
 */
class OwnerScopedDedupScope implements ResolvesDedupScope
{
    public function scopeKey(string $hash, DocumentType $type, ?Authenticatable $user, ?Model $owner): string
    {
        $ownerKey = $owner !== null ? "{$owner->getMorphClass()}:{$owner->getKey()}" : '';

        return hash('sha256', "{$type->id}|{$ownerKey}|{$hash}");
    }
}
