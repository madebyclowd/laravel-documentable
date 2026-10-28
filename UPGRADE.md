# Upgrade Guide

## Upgrading to 3.x / Next Major

### Deduplication Scoping Redesign & Proof-of-Possession Handshake

#### Default Deduplication Scope is now `TypeAndTenantScope`

In previous versions, deduplication was global by default (`HashOnlyDedupScope`), using the raw SHA-256 hash as the deduplication key. Anyone knowing a file's SHA-256 hash could attach another tenant's blob (hash oracle).

The default resolver is now `MadeByClowd\Documentable\Defaults\TypeAndTenantScope`. Reused blobs are scoped by:
1. Document type ID
2. Tenant ID
3. Raw SHA-256 content hash

The tenant ID is resolved in one of three ways:
- If the owner model implements `MadeByClowd\Documentable\Contracts\HasDedupTenant`, `$owner->dedupTenantId()` is called.
- Via a custom callback registered in a service provider: `TypeAndTenantScope::resolveTenantUsing(fn (?Model $owner, ?Authenticatable $user, DocumentType $type): ?string => ...)`.

If no tenant is resolved, the tenant part of the key is empty and the key still includes the document type. The resulting deduplication key stored in `storage_files.file_hash` is `hash('sha256', "{$typeId}|{$tenantId}|{$contentHash}")`.

#### Restoring Global Deduplication (Opt-In)

If you intentionally want cross-tenant, cross-type deduplication based solely on the file's raw content hash, configure `GlobalDedupScope` in `config/documentable.php`:

```php
'dedup' => [
    'scope_resolver' => \MadeByClowd\Documentable\Defaults\GlobalDedupScope::class,
],
```

> [!WARNING]
> Opting into `GlobalDedupScope` allows identical files uploaded across different tenants or document types to share underlying storage files. Pair this with the proof-of-possession handshake to prevent callers from claiming storage files they do not physically hold.

#### New `storage_files.content_hash` Column and Migration

Migration `2026_10_28_000015_add_content_hash_to_storage_files` adds a nullable `content_hash` column to `storage_files` and backfills it where existing `file_hash` values are 64-character hexadecimal SHA-256 hashes.

Integrity commands (`documents:verify` and `IntegrityInspector`) now compare the recomputed blob SHA-256 against `content_hash` rather than `file_hash`.

#### New `dedup_challenges` Table

Migration `2026_10_28_000016_create_dedup_challenges_table` adds the `dedup_challenges` table used by the new proof-of-possession handshake endpoints (`POST /documents/handshake` and `POST /documents/handshake/answer`). Run `php artisan migrate` after upgrading.

#### Updated `ResolvesDedupScope` Signature

If you implemented a custom `ResolvesDedupScope`, update its method signature:

```php
// Before:
public function scopeKey(string $hash, ?Model $documentable): string;

// After:
public function scopeKey(
    string $hash,
    \MadeByClowd\Documentable\Models\DocumentType $type,
    ?\Illuminate\Contracts\Auth\Authenticatable $user,
    ?\Illuminate\Database\Eloquent\Model $owner,
): string;
```
