<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Repositories;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\StoredObjectRegistrar;

class StorageFileRepository
{
    private ResolvesDedupScope $dedupScopeResolver;

    public function __construct(
        ?ResolvesDedupScope $dedupScopeResolver = null,
    ) {
        $this->dedupScopeResolver = $dedupScopeResolver ?? app(ResolvesDedupScope::class);
    }

    /**
     * Find an existing, verified storage file eligible for deduplication reuse.
     *
     * Resolves the scoped key via ResolvesDedupScope, matches by disk, and strictly ignores
     * unverified/processing files (those live under a pending: key already).
     */
    public function findReusable(
        string $hash,
        DocumentType $type,
        ?Authenticatable $user = null,
        ?Model $owner = null,
        bool $lock = false
    ): ?StorageFile {
        $scopeKey = $this->dedupScopeResolver->scopeKey($hash, $type, $user, $owner);

        if (str_starts_with($scopeKey, StoredObjectRegistrar::PENDING_KEY_PREFIX)) {
            return null;
        }

        return $this->findByHash($scopeKey, $type->disk, $lock);
    }

    /**
     * The stored file for a dedup key on one disk. Rerouted from package lookups through findReusable().
     *
     * @internal
     */
    public function findByHash(string $hash, string $disk, bool $lock = false): ?StorageFile
    {
        if (str_starts_with($hash, StoredObjectRegistrar::PENDING_KEY_PREFIX)) {
            return null;
        }

        $query = StorageFile::where('file_hash', $hash)
            ->where('disk', $disk)
            ->where('file_hash', 'not like', StoredObjectRegistrar::PENDING_KEY_PREFIX.'%');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Create the stored file for `(file_hash, disk)`, or return the one a concurrent upload created first. The
     * unique index decides the winner, so the caller compares the returned `path` with the one it tried to
     * register to learn whether it lost.
     *
     * @param  array<string, mixed>  $attributes  everything but `file_hash` and `disk`
     */
    public function createOrFirst(string $hash, string $disk, array $attributes): StorageFile
    {
        return StorageFile::createOrFirst(['file_hash' => $hash, 'disk' => $disk], $attributes);
    }

    public function delete(StorageFile $storageFile): bool
    {
        return (bool) $storageFile->delete();
    }
}
