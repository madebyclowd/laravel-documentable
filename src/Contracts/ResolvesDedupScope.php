<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Defaults\GlobalDedupScope;
use MadeByClowd\Documentable\Defaults\TypeAndTenantScope;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Controls what "the same file" means for deduplication.
 *
 * The default resolver ({@see TypeAndTenantScope}) partitions dedup
 * by document type and tenant (derived from {@see HasDedupTenant} or a configured callback).
 * Two different tenants or document types uploading identical bytes get separate `StorageFile`
 * rows and separate storage paths, preventing hash-oracle attacks where possession of another tenant's
 * SHA-256 hash grants access to their blob.
 *
 * To restore global deduplication across all types and tenants by bare hash, bind
 * {@see GlobalDedupScope} under `documentable.dedup.scope_resolver`.
 * When using global deduplication, the proof-of-possession handshake (`POST /documents/handshake`)
 * ensures callers hold the bytes before attaching.
 */
interface ResolvesDedupScope
{
    /**
     * Compute the scoped deduplication key for a file.
     *
     * `$user` is the request user when there is one and null in a queued job, and `$owner` is null when the
     * caller does not know it yet (a handshake sent without an owner). Derive the key from the document type and
     * the owner, not from the user alone: a key that needs the user cannot be recomputed in a job.
     */
    public function scopeKey(string $hash, DocumentType $type, ?Authenticatable $user, ?Model $owner): string;
}
