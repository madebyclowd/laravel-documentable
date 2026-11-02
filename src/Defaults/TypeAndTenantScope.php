<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\HasDedupTenant;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Safe default deduplication scope: keys by document type and tenant.
 *
 * The scope key is the SHA-256 hash of a delimited string containing the document type id,
 * the tenant identifier, and the bare content hash. This ensures:
 * 1. Two tenants uploading identical bytes never share a `StorageFile`.
 * 2. Two distinct document types never share a `StorageFile`.
 * 3. A client knowing only the bare content hash cannot predict or reverse the scope key.
 */
class TypeAndTenantScope implements ResolvesDedupScope
{
    /**
     * Optional static callback for resolving the tenant identifier.
     *
     * @var (callable(?Model, ?Authenticatable, DocumentType): ?string)|null
     */
    private static $tenantResolver = null;

    /**
     * Configure a runtime callback to resolve tenant identifiers when models do not implement HasDedupTenant.
     *
     * @param  (callable(?Model, ?Authenticatable, DocumentType): ?string)|null  $callback
     */
    public static function resolveTenantUsing(?callable $callback): void
    {
        self::$tenantResolver = $callback;
    }

    public function scopeKey(string $hash, DocumentType $type, ?Authenticatable $user, ?Model $owner): string
    {
        $tenant = $this->resolveTenant($owner, $user, $type);

        return hash('sha256', "{$type->id}|{$tenant}|{$hash}");
    }

    protected function resolveTenant(?Model $owner, ?Authenticatable $user, DocumentType $type): string
    {
        if ($owner instanceof HasDedupTenant) {
            $tenantId = $owner->dedupTenantId();
            if ($tenantId !== null && $tenantId !== '') {
                return (string) $tenantId;
            }
        }

        if (self::$tenantResolver !== null) {
            $tenantId = (self::$tenantResolver)($owner, $user, $type);
            if ($tenantId !== null && $tenantId !== '') {
                return (string) $tenantId;
            }
        }

        return '';
    }
}
