---
name: laravel-documentable
description: "Use this skill for madebyclowd/laravel-documentable in Laravel applications. ALWAYS use this skill when attaching files or uploads to Eloquent models, defining DocumentType configurations (size, mimes, disk, retention), accepting presigned direct-PUT or multipart uploads via upload sessions, configuring GateDocumentAuthorizer or custom authorizers, reading document contents, managing document versions, testing with Documentable::fake(), or running documents:* artisan commands."
license: MIT
metadata:
  author: madebyclowd
---

# Laravel Documentable

S3-compatible document storage for Eloquent models with content-addressed deduplication, upload sessions, version retention, presigned direct-PUT and multipart transports, and pluggable authorization.

Every write goes through `MadeByClowd\Documentable\Services\DocumentService`. Do not write to `documents` or `storage_files` tables directly.

## Define a document type

Define upload rules in `config/documentable.php` under `types` keyed by code:

```php
'types' => [
    'invoice' => [
        'name' => 'Invoice',
        'max_size_mb' => 10,
        'allowed_mimes' => ['application/pdf'],
        'disk' => 's3',
        'path_prefix' => 'invoices',
        'allows_multiple' => false,
        'requires_versioning' => true,
        'on_delete_latest' => 'promote', // 'promote', 'none', 'block'
        'versions_kept' => 5,
        'versions_retain_days' => 90,
    ],
],
```

Slot matrix:
- `allows_multiple: false`, `requires_versioning: false`: single slot, overwrite in place (e.g. avatar).
- `allows_multiple: false`, `requires_versioning: true`: single slot, full revision history (e.g. contract).
- `allows_multiple: true`, `requires_versioning: false`: multiple independent unversioned slots (e.g. receipts).
- `allows_multiple: true`, `requires_versioning: true`: multiple independently versioned slots (e.g. attachments).

Deploy step: run `php artisan documents:sync-types` on every deployment. Add `--prune` to soft-deactivate database types removed from configuration.

## Accept an upload

Uploads create an `UploadSession` before attaching to an owner model.

### Direct server-side upload
```php
$service = app(\MadeByClowd\Documentable\Services\DocumentService::class);
$session = $service->stageUpload($request->file('file'), $type, (string) $request->user()?->getAuthIdentifier());
$document = $service->attach($session, $invoice);
```

### HTTP presigned direct-PUT (< 10MB)
1. `POST /documents/presigned` with `{document_type_id, filename}` returns `{url, headers, session_id}`.
2. Client uploads raw bytes to storage `url` via `PUT`.
3. `POST /documents/presigned/finalize` with `{session_id, expected_hash?}` marks session completed.
4. Client or backend attaches session to the target model: `POST /documents/attach` or `$service->attach($session, $model)`.

### HTTP multipart upload (>= 10MB)
1. `POST /documents/multipart/initiate` with `{filename, document_type_id}` returns `{upload_id, session_id}`.
2. For each part: `POST /documents/multipart/part-url` with `{session_id, part_number}` returns `{url}`.
3. `POST /documents/multipart/complete` with `{session_id, parts?}` completes the upload.
4. If cancelled: `POST /documents/multipart/abort` with `{session_id}` drops the upload and bucket parts.

### Asynchronous verification
Uploads larger than `config('documentable.verification.sync_hash_max_bytes')` (default 50MB) create documents in `DocumentStatus::Processing`. A queued background job validates content, hashes the blob, and transitions status to `Committed` or `Rejected`. Do not serve or download documents in `Processing` status.

## Attach to a model

Add the trait to your Eloquent model:

```php
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Traits\Documentable;

class Invoice extends Model
{
    use Documentable;
}
```

CLI helper: `php artisan documents:attach-model Invoice`.

Attach an upload session or existing storage file:

```php
$document = $service->attach($session, $invoice);
$document = $service->attachStorageFile($storageFile, $type, $invoice);
```

Querying documents on a model:
```php
$invoice->documents; // MorphMany<Document>
$invoice->documents()->ofType('INVOICE')->get();
$invoices = Invoice::withDocumentOfType('INVOICE')->get();
```

## Read a document or give a download link

Do not read storage disks directly. Reading through `DocumentService` enforces committed status and access audit logging.

```php
// Read content into memory (default limit 10MB)
$content = $service->readContents($document, maxBytes: 10 * 1024 * 1024);

// Stream large files
$stream = $service->readStream($document);

// Temporary presigned URL
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

$url = $service->getUrl($document, new UrlOptions(
    ttl: now()->addMinutes(15),
    disposition: 'attachment',
    filename: 'invoice.pdf',
));
```

Shipped HTTP download routes:
- `GET /documents/{document}/view`
- `GET /documents/{document}/download`
- `GET /documents/{document}/url?disposition=attachment&expires_in_minutes=15`

## Version, restore and prune

Manage revisions and retention:

```php
// Promote a previous version to latest
$service->promote($document);

// Restore a soft-deleted document
$service->restore($document);

// Soft-delete an entire slot
$service->deleteGroup($document->document_group_id);
```

Retention configuration:
- `versions_kept`: number of past revisions to retain per slot.
- `versions_retain_days`: days to keep past versions before eligible for pruning.
- `config('documentable.retention.hold')`: array of document IDs or group IDs protected from pruning.

Prune expired revisions:
```bash
php artisan documents:prune-versions --dry-run
php artisan documents:prune-versions
```

## Authorize

Set `config('documentable.authorization.resolver')`. In production, a null resolver throws `InsecureConfiguration`.

### Option A: GateDocumentAuthorizer
Set resolver to `MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer::class` and define abilities in `AuthServiceProvider`:
- `documents.initiate-upload`: user, DocumentType
- `documents.finalize`: user, UploadSession
- `documents.attach`: user, UploadSession|Document, Model owner
- `documents.view`: user, Document
- `documents.delete`: user, Document

### Option B: Custom Authorizer
Implement `MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess` (`php artisan documents:make-authorizer`):

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;

class AppDocumentAuthorizer implements AuthorizesDocumentAccess
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

## Test

Use `Documentable::fake()` in PHPUnit test setup to swap out S3 storage and multipart drivers:

```php
use MadeByClowd\Documentable\Documentable;

protected function setUp(): void
{
    parent::setUp();
    Documentable::fake();
}
```

Dispatched events implement `ShouldDispatchAfterCommit`. Queued listeners must implement `ShouldQueueAfterCommit`.

## Operate and troubleshoot

### Operational commands
- `php artisan documents:sync-types [--prune]`: synchronize config types to database on deploy.
- `php artisan documents:doctor`: probe storage disk connectivity, permissions, and presigned URLs.
- `php artisan documents:clean-orphaned [--hours=N] [--storage-files]`: reaper for expired sessions.
- `php artisan documents:prune-versions [--dry-run]`: remove obsolete revisions beyond retention limits.
- `php artisan documents:verify [--repair]`: verify hash integrity and latest slot markers.
- `php artisan documents:configure-bucket-cors {disk} --origin=https://app.example.com`: configure S3 bucket CORS.

### Security checklist
1. Enforce morph map: call `Relation::enforceMorphMap()` in `AppServiceProvider`. Send morph alias as `documentable_type`.
2. Configure authorizer: set `authorization.resolver` in `config/documentable.php`.
3. Set disk exceptions: set `'throw' => true` on the documents disk in `config/filesystems.php`.
4. Throttle routes: define `RateLimiter::for('documents', ...)` in `AppServiceProvider`.
5. Authenticate middleware: configure `config('documentable.middleware')` with auth guards (`['web', 'auth']` or `['api', 'auth:sanctum']`).

### Troubleshooting
| Symptom | Cause | Action |
| --- | --- | --- |
| `InsecureConfiguration` on boot | Production environment without authorizer resolver | Configure `authorization.resolver` in `config/documentable.php` |
| `$request->user()` is null in authorizer | Default `middleware => ['api']` without session auth | Set `middleware` to `['web', 'auth']` or token auth |
| Direct disk read returns stale or unverified bytes | Bypassed `DocumentService` read pipeline | Use `DocumentService::readContents()` or `readStream()` |
| `purge()` returns `false` | Disk delete failed and disk has `throw => false` | Set `'throw' => true` on disk; run `documents:clean-orphaned --storage-files` |
| Queued listener executes before document is committed | Event dispatched after outermost commit | Ensure listener implements `ShouldQueueAfterCommit` |
| Browser upload blocked by CORS | Bucket lacks required headers | Run `documents:configure-bucket-cors {disk} --origin=...` |
| Model rejected on upload | Morph map alias not used or unmapped model | Register model in morph map or `security.allowed_documentable_types` |
