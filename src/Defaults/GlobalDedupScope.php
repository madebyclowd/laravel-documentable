<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Global deduplication scope: returns the bare content hash.
 *
 * Under this scope, any identical content across different owners, tenants, and document types
 * shares a single `StorageFile` row on disk. This maximizes deduplication storage savings.
 *
 * To safely opt into global deduplication, configure:
 *   'dedup' => ['scope_resolver' => \MadeByClowd\Documentable\Defaults\GlobalDedupScope::class]
 *
 * Ensure the proof-of-possession handshake is utilized so callers cannot attach existing blobs
 * without possessing the underlying bytes.
 */
class GlobalDedupScope implements ResolvesDedupScope
{
    public function scopeKey(string $hash, DocumentType $type, ?Authenticatable $user, ?Model $owner): string
    {
        return $hash;
    }
}
