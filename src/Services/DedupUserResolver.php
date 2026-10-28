<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * The user handed to a dedup scope resolver for an upload started under `$userId`. It is the request user when that
 * is the same user (the controllers refuse any other), and null otherwise, for instance in a queued job, which has
 * no request user. No lookup is made: scope keys should come from the document type and the owner, because a key
 * that depends on the user cannot be recomputed where there is none.
 */
final class DedupUserResolver
{
    public function resolve(?string $userId): ?Authenticatable
    {
        $ambient = Auth::user();

        if ($ambient === null) {
            return null;
        }

        return $userId === null || (string) $ambient->getAuthIdentifier() === $userId ? $ambient : null;
    }
}
