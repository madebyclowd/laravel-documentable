<?php

namespace MadeByClowd\Documentable\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Controls what "the same file" means for deduplication. Default
 * (MadeByClowd\Documentable\Defaults\HashOnlyDedupScope) is global by
 * content hash alone. Multi-tenant consumers bind a resolver that folds a
 * tenant identifier into the key so two tenants uploading identical bytes
 * get separate StorageFile rows instead of unintentionally sharing storage
 * (and, transitively, sharing access if a signed URL were ever guessable).
 */
interface ResolvesDedupScope
{
    public function scopeKey(string $hash, ?Model $documentable): string;
}
