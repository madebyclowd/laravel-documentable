# Upgrade Guide

## Upgrading to 3.x

### Requirements

- PHP ^8.3
- Laravel ^12|^13

### Migration order

Run the migration steps in this exact order:

1. `composer require madebyclowd/laravel-documentable:^3.0`
   Installs the 3.0 release and updates package dependencies. Failure means composer dependency conflicts that must be resolved before proceeding.
2. `php artisan documents:upgrade-check --code`
   Scans application code for removed or renamed v2 APIs and configurations. Failure means the codebase still calls removed methods that will throw errors at runtime.
3. `php artisan documents:upgrade-check`
   Preflight inspection for existing database rows that violate new integrity constraints. Failure means existing data violates single-slot latest markers or storage file uniqueness and will block migrations.
4. `php artisan documents:repair --dry-run` then `php artisan documents:repair --apply`
   Previews and applies automated repairs to demote duplicate latest versions and merge duplicate storage files. Failure means unrepairable data anomalies remain that require manual inspection.
5. `php artisan migrate`
   Executes database migrations for upload sessions, constraints, and version retention columns. Failure means the database schema is out of date and document writes will fail.
6. `php artisan documents:sync-types`
   Synchronizes document type catalog definitions from `config/documentable.php` into the database. Failure means new type configurations and retention settings are not applied.
7. `php artisan boost:update`
   Updates package guidelines and skills if Laravel Boost is installed. Failure means AI assistant tooling in your environment retains outdated v2 instructions.

### Lock behavior and rollback

The table below outlines lock behavior and rollback support across SQLite, MySQL, and PostgreSQL for all package migrations.

| Migration File | What It Does | SQLite Lock / Rewrite | MySQL Lock / Rewrite | PostgreSQL Lock / Rewrite | Reversible (`migrate:rollback`) |
| --- | --- | --- | --- | --- | --- |
| `2026_07_05_000001_create_document_types_table.php` | Creates `document_types` table. | Creates table; metadata only. | Creates table; metadata only. | Creates table; metadata only. | Yes |
| `2026_07_05_000002_create_storage_files_table.php` | Creates `storage_files` table with index on `file_hash`. | Creates table; metadata only. | Creates table; metadata only. | Creates table; metadata only. | Yes |
| `2026_07_05_000003_create_documents_table.php` | Creates `documents` table with foreign keys and unique constraint. | Creates table; metadata only. | Creates table; metadata only. | Creates table; metadata only. | Yes |
| `2026_07_05_000004_create_multipart_uploads_table.php` | Creates legacy `multipart_uploads` table. | Creates table; metadata only. | Creates table; metadata only. | Creates table; metadata only. | Yes |
| `2026_07_05_000005_create_document_access_logs_table.php` | Creates `document_access_logs` table. | Creates table; metadata only. | Creates table; metadata only. | Creates table; metadata only. | Yes |
| `2026_10_24_000006_widen_multipart_uploads_columns.php` | Widens `path` and `upload_id` columns to text and adjusts indexes. | Recreates table or alters columns. | Modifies column type and alters indexes. | Modifies column type and rebuilds indexes. | Yes |
| `2026_10_24_000007_widen_storage_files_path.php` | Widens `path` on `storage_files` to text. | Alters column or copies table. | Modifies column to text. | Metadata-only alter without table rewrite. | Yes |
| `2026_10_26_000008_create_upload_sessions_table.php` | Creates `upload_sessions` table. | Creates table; metadata only. | Creates table; metadata only. | Creates table; metadata only. | Yes |
| `2026_10_26_000009_convert_pending_documents_to_upload_sessions.php` | Data migration converting pending documents into upload sessions. | Data migration; database lock during transaction. | Data migration; row-level locks on affected rows. | Data migration; row-level locks on affected rows. | Yes |
| `2026_10_26_000010_drop_multipart_uploads_table.php` | Drops `multipart_uploads` table after verifying no active uploads. | Drops table; metadata only. | Drops table; metadata only. | Drops table; metadata only. | Yes |
| `2026_10_27_000011_use_type_id_as_single_slot_latest_marker.php` | Sets `latest_marker` on single-slot documents to `document_type_id`. | Data migration; database lock during update. | Data migration; row-level locks on updated rows. | Data migration; row-level locks on updated rows. | Yes |
| `2026_10_27_000012_add_unique_dedup_index_to_storage_files.php` | Replaces non-unique index with unique index on `(file_hash, disk)`. | Rebuilds unique index. | Builds unique index; shared read lock during creation. | Builds unique index; acquires ShareLock on table. | Yes |
| `2026_10_27_000013_type_documentable_id.php` | Converts `documents.documentable_id` to configured `documentable.key_type`. | No-op for string; alters table otherwise. | No-op for string; table rewrite with metadata lock otherwise. | No-op for string; table rewrite with AccessExclusiveLock otherwise. | Yes |
| `2026_10_27_000014_add_upload_rules_to_document_types.php` | Adds `allowed_extensions` and `trust_extension_for_generic_mime` columns. | Adds columns; metadata only. | Metadata-only on MySQL 8. | Metadata-only without table rewrite. | Yes |
| `2026_10_28_000015_add_content_hash_to_storage_files.php` | Adds `content_hash` column and index, backfilling SHA-256 values. | Adds column, creates index, chunked row updates. | Metadata-only column add on MySQL 8, index build, chunked row updates. | Metadata-only column add, index build, chunked row updates. | Yes |
| `2026_10_28_000016_create_dedup_challenges_table.php` | Creates `dedup_challenges` table for proof-of-possession handshake. | Creates table; metadata only. | Creates table; metadata only. | Creates table; metadata only. | Yes |
| `2026_10_29_000017_add_retention_and_delete_latest_to_document_types.php` | Adds retention columns, copies `max_versions_to_keep`, drops old column. | Adds columns, updates rows, drops column. | Modifies schema and updates rows. | Modifies schema and updates rows. | Yes |

### Breaking changes

### Removed upload API

The `multipart_uploads` table is replaced by `upload_sessions`. Methods `uploadDetached()`, `commit()`, and `reassociateDocument()` are removed along with the document `pending` status. Upload completion and finalization take a session ID instead of a file path. Uploads exceeding inline verification limits create documents in `processing` status.

A server-managed upload session tracks in-flight uploads across transports and prevents orphaned storage objects.

**Before:**

```php
// Detached upload with commit and reassociate
$document = $documentService->uploadDetached($file, $type);
$documentService->reassociateDocument($document, $owner);
$document->commit();

// Finalize using storage path
$document = $documentService->finalizeDirectUpload($path, $type, $owner, $originalFilename);
```

**After:**

```php
// Stage upload session and attach to owner
$session = $documentService->stageUpload($file, $type, $userId);
$document = $documentService->attach($session, $owner);

// Finalize using session ID or UploadSession model
$session = $documentService->finalizeDirectUpload($session);
$document = $documentService->attach($session, $owner);

// Check for background processing status on large files
if ($document->status === \MadeByClowd\Documentable\Enums\DocumentStatus::Processing) {
    // Queued background job will verify and transition to committed or rejected
}
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `removed-upload-api`), or grep for `uploadDetached|reassociateDocument|->commit\(|DocumentStatus::Pending|'pending'`.

**Manual step:**
Replace direct calls to detached upload and commit with `stageUpload()` and `attach()`. Update status checks to handle `DocumentStatus::Processing`.

### Authorization contract rewritten

The authorization contract is rewritten with explicit methods (`canInitiateUpload`, `canFinalize`, `canAttach`, `canView`, `canDelete`, `canViewMany`). Without a configured authorizer, the package uses `MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer`, which denies every ability your app has not defined. In production, booting without `documentable.authorization.resolver` set throws `InsecureConfiguration`. Client-supplied `user_id` fallback and `canUpload(null)` handlers are removed.

Explicit methods eliminate ambiguity around nullable owners and enforce safe defaults.

**Before:**

```php
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;

class CustomAuthorizer implements AuthorizesDocumentAccess
{
    public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $owner): bool
    {
        return $owner === null || $user?->can('upload', $owner);
    }

    public function canView(?Authenticatable $user, Document $document): bool
    {
        return true;
    }
}
```

**After:**

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;

class CustomAuthorizer implements AuthorizesDocumentAccess
{
    public function canInitiateUpload(?Authenticatable $user, DocumentType $type): bool
    {
        return $user !== null;
    }

    public function canFinalize(?Authenticatable $user, UploadSession $session): bool
    {
        return $user !== null && (string) $user->getAuthIdentifier() === (string) $session->user_id;
    }

    public function canAttach(?Authenticatable $user, UploadSession|Document $source, Model $owner): bool
    {
        return $user?->can('update', $owner) ?? false;
    }

    public function canView(?Authenticatable $user, Document $document): bool
    {
        return $user !== null;
    }

    public function canDelete(?Authenticatable $user, Document $document): bool
    {
        return $user?->can('delete', $document) ?? false;
    }

    public function canViewMany(?Authenticatable $user, Collection $documents): Collection
    {
        return $documents->filter(fn (Document $doc) => $this->canView($user, $doc));
    }
}
```

Or configure the Gate authorizer in `config/documentable.php`:

```php
'authorization' => [
    'resolver' => \MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer::class,
],
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `old-authorizer`), or grep for `PermissiveDocumentAuthorizer|canUpload\(`.

**Manual step:**
Run `php artisan documents:make-authorizer` to scaffold an authorizer class, or configure `GateDocumentAuthorizer` in `config/documentable.php`.

### Removed native checksum

The `use_native_checksum` config key and `retrieveChecksum()` driver method are removed. Uploads are verified through an automatic tiered verification pipeline.

Provider composite checksums do not match raw SHA-256 file hashes.

**Before:**

```php
// config/documentable.php
'multipart' => [
    'use_native_checksum' => true,
],
```

**After:**

```php
// config/documentable.php
'verification' => [
    'sync_hash_max_bytes' => 50 * 1024 * 1024,
    'connection' => null,
    'queue' => null,
],
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `native-checksum`), or grep for `use_native_checksum|retrieveChecksum`.

**Manual step:**
Remove `use_native_checksum` from `config/documentable.php`. Verification now runs automatically up to `verification.sync_hash_max_bytes` synchronously, and in a queued job for larger uploads.

### DocumentService internals sealed

Protected methods and properties on `DocumentService` are sealed. Subclassing is unsupported. Consumers should compose with public service methods, events, and extension hooks.

Sealing internal collaborators prevents internal refactoring from breaking downstream consumers.

| Old Protected Member | Public Seam / Replacement |
| --- | --- |
| `createDocumentFromStorageFile` | `DocumentService::attachStorageFile()` |
| `createDocumentFromUploadedPath` | `DocumentService::attach()` with `UploadSession` |
| `getOrCreateStorageFile` | `DocumentService::attachStorageFile()` or `findReusable()` |
| `logAccess` | Config key `documentable.audit.access_log` |
| `isStorageFileReferenced` | `DocumentService::removeStorageFileIfUnreferenced()` |
| `deleteBlob` | `DocumentService::purge()` or `removeStorageFileIfUnreferenced()` |
| `validateFile`, `validateSizeAndMime` | `ValidatesUploadedFile` hook or config key `documentable.validators` |
| `abortSession` | `DocumentService::abortUploadSession()` |
| `assertPathIsWithinTypePrefix` | Handled internally by session initialization |
| `assertTypeActive` | Document query scope `ofType()` |
| `sseOptions` | Configured per-disk under config key `documentable.disks` |
| `currentActorId`, `resolveActorId` | Laravel request authentication context |
| `storeUploadedFile` | `DocumentService::stageUpload()` or `upload()` |
| `resolveMultipartDriver` | Registered under config key `documentable.multipart.drivers` |
| `findOwnedSessionOrFail` | Handled by session service and authorizer `canFinalize()` |
| `resolvePartsForCompletion`, `resolveAssembledFileInfo` | Handled internally by `completeMultipartUpload()` |
| `detectMimeFromHead`, `hashAndDetectMime` | Handled internally by verification pipeline |
| `validateOrDelete`, `scanOrDelete`, `verifyIntegrityOrDelete`, `assertIntegrity` | Handled internally by verification pipeline |
| `demoteAndResolveVersion` | Handled internally by `DocumentAttacher` or `DocumentService::promote()` |
| `storageFileRepository` | Query `StorageFile` Eloquent model directly |
| `documentRepository` | Query `Document` Eloquent model directly |
| `multipartUploadRepository` | Query `UploadSession` Eloquent model directly |
| `fileScanner` | Config key `documentable.security.scanner` (`ScansUploadedFile`) |
| `dedupScopeResolver` | Config key `documentable.dedup.scope_resolver` (`ResolvesDedupScope`) |
| `pathGenerator` | Config key `documentable.storage_path.generator` (`GeneratesStoragePath`) |

**Before:**

```php
class CustomDocumentService extends DocumentService
{
    public function attachExisting(StorageFile $file, DocumentType $type, Model $owner)
    {
        return $this->createDocumentFromStorageFile($file, $type, $owner);
    }
}
```

**After:**

```php
class CustomDocumentManager
{
    public function __construct(private DocumentService $documents) {}

    public function attachExisting(StorageFile $file, DocumentType $type, Model $owner): Document
    {
        return $this->documents->attachStorageFile($file, $type, $owner);
    }
}
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `documentservice-subclass`), or grep for `extends .*DocumentService`.

**Manual step:**
Convert `DocumentService` subclasses to service composition using the public methods listed above.

### MultipartUploadDriver contract

Methods on `MultipartUploadDriver` accept `UploadSession $session` rather than individual string arguments for disk, path, and upload ID. Method `retrieveChecksum()` is removed.

Passing the session object provides complete upload context to drivers.

**Before:**

```php
public function presignPartUpload(string $disk, string $path, string $uploadId, int $partNumber): string;
public function listParts(string $disk, string $path, string $uploadId): array;
public function complete(string $disk, string $path, string $uploadId, array $parts): void;
public function abort(string $disk, string $path, string $uploadId): void;
public function retrieveChecksum(string $disk, string $path): ?string;
```

**After:**

```php
use MadeByClowd\Documentable\Models\UploadSession;

public function presignPartUpload(UploadSession $session, int $partNumber): string;
public function listParts(UploadSession $session): array;
public function complete(UploadSession $session, array $parts): void;
public function abort(UploadSession $session): void;
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `multipart-driver-contract`), or grep for `implements .*MultipartUploadDriver`.

**Manual step:**
Update custom driver method signatures. To check a driver, run it through the same assertions as `tests/Conformance/MultipartDriverConformanceTest.php` in the package repository.

### Renamed config keys

Config key `max_versions_to_keep` is renamed to `versions_kept`. Config key `lifecycle.pending_ttl_hours` is moved and renamed to `multipart.session_ttl_hours`. Config keys `security.allow_guest_user_id` and `security.enforce_path_prefix` are removed.

Configuration naming is standardized around sessions and explicit retention settings.

**Before:**

```php
// config/documentable.php
'types' => [
    'invoice' => [
        'max_versions_to_keep' => 3,
    ],
],
'lifecycle' => [
    'pending_ttl_hours' => 24,
],
'security' => [
    'allow_guest_user_id' => false,
    'enforce_path_prefix' => false,
],
```

**After:**

```php
// config/documentable.php
'types' => [
    'invoice' => [
        'versions_kept' => 3,
    ],
],
'multipart' => [
    'session_ttl_hours' => 24,
],
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `renamed-config`), or grep for `max_versions_to_keep|pending_ttl_hours|allow_guest_user_id|enforce_path_prefix`.

**Manual step:**
Update keys in `config/documentable.php` and remove discontinued keys.

### Delete-latest promotes by default <a id="delete-latest-promotes"></a>

Deleting the latest document version automatically promotes the previous version to latest. Configurable via `on_delete_latest` on document types (`promote`, `none`, or `block`). To preserve v2 behavior, set `on_delete_latest` to `'none'`. New management methods `restore()`, `promote()`, and `deleteGroup()` are available. Type retention settings `versions_kept` and `versions_retain_days`, config key `documentable.retention.hold`, command `php artisan documents:prune-versions`, and events `DocumentVersionPromoted` and `DocumentVersionPruned` handle version lifecycle.

Automatic promotion avoids orphaned history when the current version is deleted.

**Before:**
Deleting the latest version left the document group with no latest record.

**After:**

```php
// Configure type retention and deletion behavior in config/documentable.php
'types' => [
    'contract' => [
        'on_delete_latest' => 'promote', // Options: 'promote', 'none', 'block'
        'versions_kept' => 5,
        'versions_retain_days' => 90,
    ],
],

// Version management methods
$documentService->restore($document);
$documentService->promote($document);
$documentService->deleteGroup($groupId);

// Retention hold list in config/documentable.php
'retention' => [
    'hold' => ['hold-doc-id-1', 'hold-group-id-1'],
],
```

Prune old versions with the console command:

```bash
php artisan documents:prune-versions --dry-run
```

**Detect it:**
Inspect document types where `on_delete_latest` is not set; check for previous version deletion handlers.

**Manual step:**
Set `on_delete_latest => 'none'` on document types where automatic promotion is unwanted.

### Direct storage reads

Direct `Storage::disk(...)->get()` calls on document paths should be replaced with `readContents()` or `readStream()`.

Reading through the service enforces committed status, size limits, and access logging.

**Before:**

```php
$content = Storage::disk($document->storageFile->disk)->get($document->storageFile->path);
```

**After:**

```php
$content = $documentService->readContents($document, maxBytes: 10 * 1024 * 1024);

// For large files, use stream:
$stream = $documentService->readStream($document);
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `direct-storage-read`), or grep for `Storage::(?:disk\(.*?\)->)?(?:get|readStream|read)`.

**Manual step:**
Replace direct storage disk reads with `$documentService->readContents()` or `readStream()`.

### Package migrations

Published migrations in `database/migrations/` that differ from updated package migrations must be reviewed or re-published.

Package migrations include new columns, constraints, and table definitions required by v3.

**Before:**
Host application contains outdated or modified copies of package migrations.

**After:**
Host application migrations match package migrations.

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `package-migration-diverges`).

**Manual step:**
Re-publish migrations with `php artisan vendor:publish --tag=documentable-migrations --force` or reconcile intentional modifications.

### Document type queries

Querying documents by type code using `whereHas('documentType', ...)` should be replaced with the `ofType($code)` scope. Owner models can eager-load document types using `withDocumentOfType($code)`.

The `ofType()` scope keeps the type lookup in one place, so your queries do not repeat the relation.

**Before:**

```php
$documents = Document::whereHas('documentType', fn ($q) => $q->where('code', 'INVOICE'))->get();
```

**After:**

```php
$documents = Document::ofType('INVOICE')->get();

// Eager load on owner model:
$users = User::withDocumentOfType('AVATAR')->get();
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `document-type-query`), or grep for `whereHas\(['"]documentType['"]`.

**Manual step:**
Update query builder calls to use `ofType($code)` and `withDocumentOfType($code)`.

### Testing fake driver moved

Test fake class `Tests\Fixtures\FakeMultipartUploadDriver` moved to `MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver`. Use `Documentable::fake()` in tests.

Testing utilities are consolidated into the package public API.

**Before:**

```php
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
```

**After:**

```php
use MadeByClowd\Documentable\Documentable;

Documentable::fake();
```

**Detect it:**
Run `php artisan documents:upgrade-check --code` (rule `fake-driver-moved`), or grep for `Tests\\Fixtures\\FakeMultipartUploadDriver`.

**Manual step:**
Replace manual fake driver setup with `Documentable::fake()`.

### Deterministic single-slot marker and unique dedup index

For single-slot types (`allows_multiple = false`), `latest_marker` is now set deterministically to `document_type_id`. In addition, a unique index is enforced on `storage_files(file_hash, disk)`.

This constraint prevents duplicate latest documents and duplicate stored blobs across engines.

Before running `php artisan migrate`, check and repair any existing duplicates:

```bash
php artisan documents:upgrade-check
php artisan documents:repair --dry-run
php artisan documents:repair --apply
```

### Typed owner key

Column `documents.documentable_id` can be converted to match your application primary key type using config key `documentable.key_type` (`'string'`, `'int'`, `'uuid'`, or `'ulid'`).

Match it when every owner model shares one key type. Keep `'string'` when owners have different key types.

### URL options and download routes

Method `DocumentService::getUrl()` accepts a `MadeByClowd\Documentable\ValueObjects\UrlOptions` instance:

```php
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

$url = $documentService->getUrl(
    $document,
    new UrlOptions(
        ttl: now()->addMinutes(15),
        disposition: 'attachment',
        filename: 'report.pdf'
    )
);
```

Two download routes are registered under the package route prefix:
- `GET /documents/{document}/download`
- `GET /documents/{document}/view`

### Deduplication Scoping Redesign & Proof-of-Possession Handshake

See details below for deduplication scopes and the proof-of-possession handshake.

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
