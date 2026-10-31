# Laravel Documentable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![Total Downloads](https://img.shields.io/packagist/dt/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![run-tests](https://img.shields.io/github/actions/workflow/status/madebyclowd/laravel-documentable/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/madebyclowd/laravel-documentable/actions/workflows/run-tests.yml)
[![security](https://img.shields.io/github/actions/workflow/status/madebyclowd/laravel-documentable/security.yml?branch=main&label=security&style=flat-square)](https://github.com/madebyclowd/laravel-documentable/actions/workflows/security.yml)
[![codecov](https://img.shields.io/codecov/c/github/madebyclowd/laravel-documentable?style=flat-square)](https://codecov.io/gh/madebyclowd/laravel-documentable)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Document storage for Laravel models on S3-compatible buckets. It provides content-addressable deduplication, composable versioning, presigned direct-PUT and multipart uploads, and automated orphan cleanup without forcing a storage backend or admin UI.

```bash
composer require madebyclowd/laravel-documentable
```

## Quick start (5 minutes)

Follow these steps to install the package, define a document type, attach documents to an Eloquent model, upload a file, and read it back.

### 1. Install the package

```bash
composer require madebyclowd/laravel-documentable
php artisan documents:install
```

The installer publishes the configuration and database migrations, offers to run `php artisan migrate`, and sets your owner key type, application shape, multipart ETag strategy, and catalog mode.

In production, booting with package routes mounted requires an authorizer. Scaffold an authorizer class:

```bash
php artisan documents:make-authorizer AppDocumentAuthorizer --style=class
```

Then configure it in `config/documentable.php`:

```php
'authorization' => [
    'resolver' => \App\Documentable\AppDocumentAuthorizer::class,
],
```

To use Laravel Gates instead, set the resolver to `MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer::class`, or run `php artisan documents:make-authorizer --style=gates` and define the gate abilities in your service provider. Leaving the resolver unset uses the same Gate-backed authorizer, which denies every ability you have not defined, and production boot throws until you set it.

### 2. Define a document type

Add a document type definition in `config/documentable.php`:

```php
'types' => [
    'invoice-pdf' => [
        'name' => 'Invoice PDF',
        'max_size_mb' => 10,
        'allowed_mimes' => ['application/pdf'],
        'disk' => 's3',
        'path_prefix' => 'invoices',
        'requires_versioning' => true,
        'allows_multiple' => false,
    ],
],
```

Sync the type configuration into your database:

```bash
php artisan documents:sync-types
```

### 3. Attach the trait to your model

Add `use Documentable;` to your owning model:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Traits\Documentable;

class Invoice extends Model
{
    use Documentable;
}
```

Or run the helper command:

```bash
php artisan documents:attach-model Invoice
```

### 4. Upload a file

Upload a file from a controller action using `DocumentService`:

```php
namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;

class InvoiceDocumentController extends Controller
{
    public function store(Request $request, Invoice $invoice, DocumentService $service): JsonResponse
    {
        $type = DocumentType::where('code', 'invoice-pdf')->firstOrFail();

        $document = $service->upload(
            $request->file('file'),
            $type,
            $invoice
        );

        return response()->json($document, 201);
    }
}
```

### 5. Read the document back

```php
// Retrieve the latest document for the invoice:
$document = $invoice->documentOfType('invoice-pdf')->first();

// Generate a temporary download URL valid for 5 minutes:
$url = $service->getUrl($document, now()->addMinutes(5));

// Or read the raw file contents safely:
$contents = $service->readContents($document);
```

To upload from client applications directly to storage over HTTP, see [Upload flows and the client](#upload-flows-and-the-client).

## Concepts

### Document types

A `DocumentType` defines rules for an upload category:
- `code` and `name`: unique identifier and label.
- `max_size_mb`: maximum allowed file size.
- `allowed_mimes`: list of allowed MIME types evaluated from file bytes.
- `allowed_extensions`: optional allowed file extensions.
- `disk`: storage disk defined in `config/filesystems.php`.
- `path_prefix`: storage directory prefix for objects.
- `requires_versioning`: whether replacing a file creates a new version or replaces in place.
- `allows_multiple`: whether an owner can hold multiple independent slots of this type.
- `versions_kept` and `versions_retain_days`: automatic retention thresholds.
- `on_delete_latest`: policy when the latest version is soft-deleted (`promote`, `none`, or `block`).

### Upload sessions

All uploads run on server-managed `UploadSession` records. The client holds only the session ID. The server generates storage keys and keeps provider upload IDs private. An upload session progresses through five states: `initiated`, `uploaded`, `attached`, `expired`, and `aborted`.

### Attach

Once raw bytes reach storage and pass initial size and MIME checks, the session status is `uploaded`. Attaching the session to an owner model creates the `Document` record. Attach happens via `POST /documents/attach` over HTTP or through `DocumentService::attachStorageFile()` in PHP.

### Versions and slots

- `allows_multiple = false` (single slot): the owner has one document slot of this type. Uploading a replacement targets that slot.
- `allows_multiple = true` (multi-slot): the owner can hold multiple independent document groups. Each group has its own `document_group_id`.
- `requires_versioning = true`: subsequent uploads to the same slot increment the version number (`version = 1, 2, 3...`) and update the latest pointer.
- `requires_versioning = false`: new uploads replace the active document in place.

### Document statuses

Documents transition through three statuses:
- `committed`: the file passed size, MIME, hash, and virus scanning checks. It is active, ready to serve, and eligible for deduplication.
- `processing`: uploads larger than `documentable.verification.sync_hash_max_bytes` create documents in `processing` status. A queued job computes hashes and runs virus scanners in the background. While `processing`, a document cannot be downloaded or deduplicated against.
- `rejected`: background verification detected a size, MIME, or hash mismatch, or a virus scanner failure. The document cannot be served.

### Glossary

| Term | Meaning |
|---|---|
| Disk | Flysystem disk name in `config/filesystems.php`. Requires an S3-compatible service: AWS S3, Cloudflare R2, MinIO, or DigitalOcean Spaces. |
| DocumentType | A named category of upload with rules: maximum size, allowed formats, disk, prefix, and retention policies. |
| Documentable | The Eloquent model that owns documents (`Invoice`, `User`, `Project`). Uses the `Documentable` trait. |
| Dedup (deduplication) | Storing identical file contents once by SHA-256 hash and sharing the `StorageFile` across documents within the configured scope. |
| Versioning | Preserving past file versions for a slot when a new file is uploaded. |
| Presigned URL | A short-lived URL that lets a client send bytes directly to storage without routing files through your Laravel server. |
| Multipart upload | Chunked upload for large files. The file is split into parts uploaded independently to the storage provider. |
| ETag | A checksum returned by storage for uploaded parts, used during multipart completion. |
| Morph type / `documentable_type` | Eloquent morph class or morph map alias for the model owning the document. |
| Authorizer | Implementation of `AuthorizesDocumentAccess` deciding upload, attach, view, and delete permissions. |

## Authorization

The package delegates authorization decisions to implementations of `MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess`.

### Authorizer methods

Every method receives the authenticated user (or `null` for unauthenticated requests) and the subject being acted on:

- `canInitiateUpload(?Authenticatable $user, DocumentType $type): bool`: decides whether the user may start an upload session for this document type. Also filters the types returned by `GET /documents/types`.
- `canFinalize(?Authenticatable $user, UploadSession $session): bool`: decides whether the user may finalize or check status on an in-flight upload session.
- `canAttach(?Authenticatable $user, UploadSession|Document $source, Model $owner): bool`: decides whether the user may attach an upload session or document to the specified owner model.
- `canView(?Authenticatable $user, Document $document): bool`: decides whether the user may view or download the document.
- `canDelete(?Authenticatable $user, Document $document): bool`: decides whether the user may soft-delete the document.
- `canViewMany(?Authenticatable $user, Collection $documents): Collection`: filters a collection of documents for bulk listing queries (`GET /documents`), returning only visible items.

### Gate document authorizer

The default authorizer is `MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer`. It routes each authorization check to Laravel's Gate:

- `documents.initiate-upload` (`DocumentType $type`)
- `documents.finalize` (`UploadSession $session`)
- `documents.attach` (`UploadSession|Document $source`, `Model $owner`)
- `documents.view` (`Document $document`)
- `documents.delete` (`Document $document`)

If a gate ability is undefined, `GateDocumentAuthorizer` falls back to matching policy methods on registered model policies (`initiateUpload`, `finalize`, `attach`, `view`, `delete`). If no policy or ability handles the check, access is denied.

### Production boot check

In production (`app()->isProduction()`), booting with package routes enabled and `documentable.authorization.resolver` set to `null` throws `MadeByClowd\Documentable\Exceptions\InsecureConfiguration`. You must configure an explicit authorizer before deploying.

### Scaffold an authorizer

Scaffold an authorizer using the Artisan command:

```bash
# Scaffold a dedicated class:
php artisan documents:make-authorizer AppDocumentAuthorizer --style=class

# Or scaffold a Gate definition provider:
php artisan documents:make-authorizer AppDocumentGates --style=gates
```

Configure your authorizer in `config/documentable.php`:

```php
'authorization' => [
    'resolver' => \App\Documentable\AppDocumentAuthorizer::class,
],
```

## Upload flows and the client

The package provides routes under `/documents` (`config('documentable.load_routes')`, default `true`). File bytes transfer directly from client to storage, keeping file streams off your application server.

Routes mount under `config('documentable.middleware')` (default `['api']`). For session-based monoliths (Inertia, Livewire, Blade), configure `['web', 'auth']` so `$request->user()` is populated.

### Listing document types

```http
GET /documents/types
```

Returns active document types for which `canInitiateUpload` returns `true`.

### Small files with presigned PUT

For files below `multipart.threshold_bytes` (default 10MB):

1. Request a presigned URL:
   ```http
   POST /documents/presigned
   Content-Type: application/json

   {
     "document_type_id": "9c0a1b2c-3d4e-5f6a-7b8c-9d0e1f2a3b4c",
     "filename": "invoice.pdf",
     "file_size": 1048576
   }
   ```
   Response (`200 OK`):
   ```json
   {
     "session_id": "8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d",
     "url": "https://storage.example.com/invoices/...",
     "headers": {},
     "expires_at": "2026-10-31T20:00:00+00:00"
   }
   ```

2. The client sends a `PUT` request with raw file bytes directly to `url`.

3. Finalize the upload:
   ```http
   POST /documents/presigned/finalize
   Content-Type: application/json

   {
     "session_id": "8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d",
     "expected_hash": "a1b2c3d4..."
   }
   ```

4. Attach the upload to an owner:
   ```http
   POST /documents/attach
   Content-Type: application/json

   {
     "session_id": "8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d",
     "documentable_type": "invoice",
     "documentable_id": "42"
   }
   ```
   Response (`201 Created`) returns the new `Document` resource. You can also pass `documentable_type` and `documentable_id` directly to `/finalize` to finalize and attach in one request.

### Large files with multipart uploads

For files at or above `multipart.threshold_bytes`:

1. Initiate the multipart session:
   ```http
   POST /documents/multipart/initiate
   Content-Type: application/json

   {
     "document_type_id": "9c0a1b2c-3d4e-5f6a-7b8c-9d0e1f2a3b4c",
     "filename": "archive.zip",
     "declared_size": 104857600
   }
   ```
   Response (`201 Created`):
   ```json
   {
     "id": "8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d",
     "part_size": 10485760,
     "status": "initiated"
   }
   ```

2. Request presigned upload URLs for each part (numbered from 1):
   ```http
   POST /documents/multipart/part-url
   Content-Type: application/json

   {
     "session_id": "8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d",
     "part_number": 1
   }
   ```
   Response: `{ "url": "https://..." }`. The client sends a `PUT` with that part's bytes to the URL. Under `etag_strategy = client`, save the `ETag` header from each PUT response. Under `server-authoritative`, the server reads parts from storage directly.

3. Complete the upload:
   ```http
   POST /documents/multipart/complete
   Content-Type: application/json

   {
     "session_id": "8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d",
     "expected_hash": "e3b0c44...",
     "parts": [{"PartNumber": 1, "ETag": "\"hash\""}]
   }
   ```

4. Attach via `POST /documents/attach` (or include `documentable_type` and `documentable_id` in `/complete`).

If an upload fails or the user cancels, call abort:
```http
POST /documents/multipart/abort
Content-Type: application/json

{
  "session_id": "8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d"
}
```

To resume after a network interruption, check session status and uploaded parts:
```http
GET /documents/multipart/status?session_id=8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d
GET /documents/multipart/parts?session_id=8a1b2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d
```

### Deduplication handshake

To prevent hash oracle attacks when deduplicating files, clients perform a proof-of-possession handshake:

1. Request a challenge:
   ```http
   POST /documents/handshake
   Content-Type: application/json

   {
     "type": "invoice-pdf",
     "hash": "<sha256-hex>",
     "size": 1048576,
     "documentable_type": "invoice",
     "documentable_id": "42"
   }
   ```
   Response (`200 OK`):
   ```json
   {
     "challenge_id": "...",
     "nonce": "...",
     "ranges": [{"offset": 1024, "length": 4096}]
   }
   ```
   If the file does not exist, the server returns a decoy challenge with identical structure to prevent timing and enumeration attacks.

2. Submit proof of possession:
   ```http
   POST /documents/handshake/answer
   Content-Type: application/json

   {
     "challenge_id": "...",
     "proof": "<sha256-of-nonce-and-requested-byte-ranges>",
     "filename": "invoice.pdf"
   }
   ```
   On verification success, the server returns an `UploadSession` in `uploaded` status ready to attach. On failure, HTTP 422 is returned. Three invalid attempts expire the challenge.

### Direct service calls

You can run identical workflows programmatically using `DocumentService`:

```php
use App\Models\Invoice;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;

// Upload a local file directly:
$document = $service->upload($uploadedFile, $type, $invoice);

// Attach an existing storage file (deduplication seam):
$newDocument = $service->attachStorageFile(
    $storageFile,
    $type,
    $invoice,
    'report.pdf',
    new AttachOptions(metadata: ['source' => 'import'])
);
```

### Frontend integration notes

- Compare `file.size` against `multipart.threshold_bytes` on the client. Route to `/documents/presigned` below the threshold and `/documents/multipart/initiate` at or above it.
- Responses return raw JSON bodies without status envelopes.
- S3 requires minimum 5MB part sizes except for the final part. 5MB to 10MB parts are recommended.
- Under `etag_strategy = client`, capture the `ETag` response header from each part PUT.
- Call `/documents/multipart/abort` when an upload is cancelled to release bucket storage immediately.
- Retry failed part PUTs before abandoning the upload.
- Compute SHA-256 on the client when possible.
- Pass the morph alias and key for `documentable_type` and `documentable_id`.

## Reading documents

### Model accessors and scopes

`Document` models provide accessors for file properties:

```php
$document->filename;       // Client filename (e.g. "report.pdf")
$document->size;           // File size in bytes from the storage file
$document->mime;           // MIME type from the storage file (e.g. "application/pdf")
$document->extension;      // Lowercase extension from filename ("pdf"), or "" when none
$document->temporaryUrl(); // Presigned download URL delegating to DocumentService::getUrl()
```

If the `storageFile` relation is not already loaded, accessing `size` or `mime` loads it lazily once. If the backing storage file record is missing, a `LogicException` is thrown.

Under `Model::shouldBeStrict()`, Eloquent prevents accessing missing attributes on models. Defined accessors above are always safe to read, whereas accessing undefined properties throws an exception.

Query scopes on `Document`:

```php
// Only the current version of each group or slot (Eloquent's own latest() orders by created_at)
Document::latestVersion()->get();

// Only committed documents
Document::committed()->get();

// Filter by document type code in a single subquery (no whereHas per row)
Document::ofType('INVOICE')->get();
```

### Owner model helpers

Models using the `Documentable` trait can query their documents by type:

```php
// The latest document of a type, in one query:
$avatar = $user->documentOfType('avatar')->first();

// MorphMany relation to all documents of a type:
$invoices = $user->documentsOfType('invoice')->get();

// Eager load for many owners in one extra query. Only the latest document of that type lands in `documents`:
$users = User::withDocumentOfType('avatar')->get();
```

Helpers respect string `key_type` configurations and integer primary key owners.

### Reading contents and streams

Read stored bytes safely without bypassing the service or directly accessing storage disks:

```php
// Open a readable stream (PHP resource):
$stream = $documentService->readStream($document);
$firstChunk = fread($stream, 8192);
fclose($stream);

// Read entire contents into a string with a memory limit guard (default 10MB):
$contents = $documentService->readContents($document, maxBytes: 5 * 1024 * 1024);
```

Both methods refuse non-committed documents (`DocumentNotReady`), throw `DocumentTooLargeToRead` when exceeding the byte limit, and record an access log entry with action `read` when `documentable.audit.access_log` is enabled.

### API resources

Use `DocumentResource` to serialize documents with `id`, `filename`, `size`, `mime`, `version`, and `type`.

To prevent accidental generation of presigned URLs during bulk listings, presigned `url` generation is off by default. Opt in explicitly with `->withUrl()`:

```php
use MadeByClowd\Documentable\Http\Resources\DocumentResource;

// Presigned URL omitted by default:
return new DocumentResource($document);

// Presigned URL included:
return (new DocumentResource($document))->withUrl();

// Collections:
return DocumentResource::collection($documents)->withUrl();
```

### Download links

Stable HTTP endpoints for viewing and downloading documents:

```http
GET /documents/{document}/download
GET /documents/{document}/view
```

- `download` redirects (302) to a presigned URL with `Content-Disposition: attachment`.
- `view` redirects (302) to a presigned URL with `Content-Disposition: inline`.
- Responses include `Cache-Control: no-store` to prevent caching the redirect beyond URL expiration.
- Authorized through `AuthorizesDocumentAccess::canView` (returns 403 on denial).
- Returns 409 Conflict if the document is still `processing` or was `rejected`.
- Returns 404 if the document is missing or soft-deleted.
- Generates access audit log entries when `documentable.audit.access_log` is enabled.

### Custom URL options and modifiers

When generating URLs programmatically via `DocumentService::getUrl()` or `$document->temporaryUrl()`, pass a `UrlOptions` value object:

```php
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

$options = new UrlOptions(
    ttl: now()->addMinutes(15),
    disposition: 'inline',
    filename: 'custom-export-name.pdf',
    contentType: 'application/pdf',
);

$url = $documentService->getUrl($document, $options);
```

For security, `inline` disposition only allows content types from a safe allowlist (`image/png`, `image/jpeg`, `image/webp`, `image/gif`, `application/pdf`). HTML and SVG are rejected to prevent XSS.

To hook into URL generation globally, implement `ModifiesUrlOptions` and set `documentable.url.modifier` in config:

```php
use MadeByClowd\Documentable\Contracts\ModifiesUrlOptions;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

class CustomUrlModifier implements ModifiesUrlOptions
{
    public function modify(UrlOptions $options, Document $document): UrlOptions
    {
        return new UrlOptions(
            ttl: $options->ttl,
            disposition: $options->disposition,
            filename: 'prefix-'.$options->filename,
            contentType: $options->contentType,
        );
    }
}
```

## Versions and retention

### Delete latest policy (`on_delete_latest`)

When soft-deleting the active latest version of a document group, the type's `on_delete_latest` configuration determines the outcome:
- `promote` (default): automatically promotes the highest remaining committed version in the group to become latest, maintaining the latest pointer.
- `none`: clears the latest marker, leaving the group without an active latest document.
- `block`: prevents deletion and throws `MadeByClowd\Documentable\Exceptions\CannotDeleteLatestVersion` if older versions exist in the group.

Configure this on the document type:

```php
'types' => [
    'contract' => [
        'name' => 'Contract',
        'disk' => 's3',
        'requires_versioning' => true,
        'on_delete_latest' => \MadeByClowd\Documentable\Enums\OnDeleteLatest::Promote, // 'promote', 'none', or 'block'
    ],
],
```

When deleting a latest document under the `promote` policy, `DocumentService::delete()` dispatches `MadeByClowd\Documentable\Events\DocumentVersionPromoted`:

```php
$service->delete($latestDocument);
// Next highest committed version is now promoted to is_latest = true
```

### Restoring versions (`restore()`)

Restoring a soft-deleted document brings the record back into active state. It reclaims the latest slot only if no higher version exists and the latest slot is unoccupied; otherwise it restores as an older historical version:

```php
$restored = $service->restore($trashedDocument);
```

### Promoting versions (`promote()`)

To make a specific historical version the latest version of its group, call `promote()`. The current latest version is demoted in the same transaction under a database group lock, and `MadeByClowd\Documentable\Events\DocumentVersionPromoted` is dispatched:

```php
$newLatest = $service->promote($olderDocument);
```

### Deleting a version group (`deleteGroup()`)

To soft-delete all active versions belonging to a group at once without triggering version promotion:

```php
$deletedCount = $service->deleteGroup($document->document_group_id);
```

### Retention policies (`versions_kept` and `versions_retain_days`)

Document types can configure automatic retention rules:
- `versions_kept`: integer count of versions to keep per slot.
- `versions_retain_days`: integer age in days to retain historical versions.

Both axes operate together: a version is pruned only when it exceeds both count and age thresholds. A null axis never prunes on that dimension. If both are null, historical versions are retained indefinitely. The latest version is never pruned by retention.

```php
'types' => [
    'audit-log' => [
        'name' => 'Audit Log',
        'disk' => 's3',
        'requires_versioning' => true,
        'versions_kept' => 10,           // Retain at least 10 versions
        'versions_retain_days' => 30,    // Retain versions up to 30 days old
    ],
],
```

### Retention hold list (`retention.hold`)

To protect specific documents or entire groups from being pruned, add their IDs to `config('documentable.retention.hold')` (`documentable.retention.hold`):

```php
'retention' => [
    'hold' => [
        '9c0a1b2c-3d4e-5f6a-7b8c-9d0e1f2a3b4c', // Specific document ID
        '8b1a2c3d-4e5f-6a7b-8c9d-0e1f2a3b4c5d', // Entire document group ID
    ],
],
```

### Pruning versions (`documents:prune-versions`)

Run the prune command on a schedule to evaluate retention rules and permanently remove expired historical versions:

```bash
# Preview versions eligible for pruning:
php artisan documents:prune-versions --dry-run

# Prune versions for all document types:
php artisan documents:prune-versions

# Prune versions for a specific type:
php artisan documents:prune-versions --type=audit-log
```

Pruning dispatches `MadeByClowd\Documentable\Events\DocumentVersionPruned` for each purged version.

## Operations

### Storage doctor

Run diagnostic probes against your storage bucket before deploying:

```bash
php artisan documents:doctor s3
```

Checks permissions, bucket connectivity, CORS configuration, multipart support, and checksum handling.

### Orphaned upload reaper

The reaper expires stalled upload sessions and aborts dangling provider multipart uploads:

```bash
php artisan documents:clean-orphaned
```

- Run on a schedule via `config('documentable.lifecycle.reaper_frequency')` (default `hourly`).
- Pass `--hours=N` to override the age threshold.
- Pass `--storage-files` to retry deleting unreferenced stored files.
- Pass `--bucket` to inspect untracked objects directly in your bucket under type prefixes (add `--apply` to delete).

### Integrity verification and repair

Inspect database integrity for latest marker and slot invariants:

```bash
php artisan documents:verify
```

Preview and apply automated integrity repairs:

```bash
php artisan documents:repair --dry-run
php artisan documents:repair --apply
```

### Bucket configuration

Configure bucket CORS and lifecycle rules directly from Artisan:

```bash
# Configure bucket CORS:
php artisan documents:configure-bucket-cors s3 --origin=https://app.example.com --verify

# Configure bucket lifecycle rules:
php artisan documents:configure-bucket-lifecycle s3 --days=3
```

### Provider notes

This table compares multipart behavior across S3-compatible providers:

| Provider | Minimum part size (except last) | Max parts | Equal part sizes required | Upload ID length | Single PutObject SHA-256 verification | Browser needs CORS ExposeHeaders ETag | Unfinished uploads auto-expire |
|---|---|---|---|---|---|---|---|
| AWS S3 | 5 MiB | 10,000 | no | short | accepted | yes | lifecycle rule required |
| Cloudflare R2 | 5 MiB | 10,000 | yes (fixed part size) | 343 chars | yes (BadDigest) | yes | lifecycle rule required |
| MinIO / RustFS | 5 MiB | 10,000 | no | short | yes | yes | lifecycle rule required |
| Backblaze B2 | 5 MB | 10,000 | no | variable | accepted | yes | lifecycle rule required |
| DO Spaces | 5 MiB | 10,000 | no | variable | accepted | yes | lifecycle rule required |

### Behind a proxy

When clients reach storage at a different address than your application servers do, configure `presign_endpoint` under `disks` in `config/documentable.php`:

```php
'disks' => [
    's3' => [
        'presign_endpoint' => 'https://downloads.example.com',
        'presign_use_path_style' => false,
    ],
],
```

A dedicated client signs download and upload URLs for that host, while server-side reads and deletes keep using the disk's internal endpoint.

### Deploy checklist

1. Run `php artisan documents:sync-types` on every deploy next to `php artisan migrate`.
2. Set `'throw' => true` on the disk configuration in `config/filesystems.php`.
3. Run `php artisan documents:configure-bucket-lifecycle {disk}` once per bucket.
4. Define a rate limiter for `documentable.throttle` in `AppServiceProvider`.
5. Ensure a queue worker is active to process background verification for documents in `processing` status.

## Configuration reference

Published to [`config/documentable.php`](config/documentable.php):

```php
return [
    // Default Flysystem disk name
    'disk' => env('DOCUMENTABLE_DISK', 's3'),

    // Owner key column type: 'string', 'int', 'uuid', or 'ulid'
    'key_type' => 'string',

    // Whether to auto-load package migrations
    'load_migrations' => true,

    // Whether to register package HTTP routes
    'load_routes' => true,

    // Code-first document types catalog
    'types' => [
        // 'invoice' => [...]
    ],

    // Content validators for custom format verification
    'validators' => [],

    // Multipart upload configuration
    'multipart' => [
        'threshold_bytes' => 10 * 1024 * 1024,
        'etag_strategy' => 'server-authoritative', // 'server-authoritative' or 'client'
        'part_upload_url_ttl' => '+1 hour',
        'session_ttl_hours' => 24,
        'drivers' => [
            's3' => \MadeByClowd\Documentable\Drivers\S3MultipartDriver::class,
        ],
        'object_metadata' => false,
        'content_type' => null,
    ],

    // Tiered verification settings
    'verification' => [
        'sync_hash_max_bytes' => 50 * 1024 * 1024, // 50MB inline limit
        'connection' => null,
        'queue' => null,
    ],

    // Orphan cleanup and reaper settings
    'lifecycle' => [
        'reaper_frequency' => 'hourly',
        'storage_file_grace_hours' => 24,
    ],

    // Authorization resolver contract
    'authorization' => [
        'resolver' => null, // Required in production
    ],

    // Deduplication scoping and handshake
    'dedup' => [
        'scope_resolver' => null,
        'challenge_ttl' => 300,
        'max_range_bytes' => 64 * 1024,
        'throttle' => 'documents-handshake',
    ],

    // Security policies
    'security' => [
        'scanner' => null,
        'allowed_documentable_types' => null,
        'scan_error_policy' => 'reject', // 'reject' or 'allow'
    ],

    // Temporary URL options
    'url' => [
        'max_ttl_minutes' => 1440,
        'modifier' => null,
    ],

    // Storage path generator
    'storage_path' => [
        'generator' => null,
    ],

    // Per-disk S3 client overrides
    'disks' => [
        // 's3' => ['presign_endpoint' => 'https://...', 'server_side_encryption' => 'AES256'],
    ],

    // Route middleware stack
    'middleware' => ['api'], // Use ['web', 'auth'] for session monoliths

    // Pagination for GET /documents
    'listing' => [
        'per_page' => 50,
    ],

    // Route rate limiter name
    'throttle' => 'documents',

    // Audit and access logging
    'audit' => [
        'enabled' => false,
        'access_log' => false,
    ],

    // Version retention hold list
    'retention' => [
        'hold' => [],
    ],

    // Storage doctor diagnostic prefix
    'doctor' => [
        'scratch_prefix' => 'documentable-doctor',
    ],
];
```

## Security

### Morph map enforcement

All upload and attach requests resolve `documentable_type` through `Relation::getMorphedModel()`. Enforce morph maps in your application service provider:

```php
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::enforceMorphMap([
    'invoice' => Invoice::class,
    'user' => User::class,
]);
```

Unmapped model classes are rejected by default unless explicitly listed in `documentable.security.allowed_documentable_types`. Clients should send the morph map alias (`invoice`), never the internal class FQCN.

### Production authorization requirement

In production, booting with package routes mounted and `documentable.authorization.resolver` set to `null` throws `MadeByClowd\Documentable\Exceptions\InsecureConfiguration`. You must bind an authorizer class implementing `AuthorizesDocumentAccess` or configure `GateDocumentAuthorizer`.

### Scanner error policy

When an antivirus scanner returns `ScanResult::Error` (for example, during scanner service downtime), the default policy `documentable.security.scan_error_policy = 'reject'` refuses the upload and removes the storage file. If file availability is preferred over strict scanning during outages, set the policy to `'allow'`.

### Upload sessions and storage security

Every upload is bound to a server-managed `UploadSession` record. Storage keys are generated server-side and never revealed to client callers. Clients reference uploads solely through session IDs, preventing storage key tampering and unauthorized path traversal.

## Testing your app

The package provides testing tools so application tests can exercise document workflows without real S3 buckets or external network calls:

```php
namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use MadeByClowd\Documentable\Documentable;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use Tests\TestCase;

class InvoiceUploadTest extends TestCase
{
    public function test_user_can_upload_invoice(): void
    {
        $fake = Documentable::fake();

        $type = DocumentType::factory()->create([
            'code' => 'INVOICE',
            'disk' => 's3',
        ]);
        $invoice = Invoice::factory()->create();

        $service = app(DocumentService::class);
        $document = $service->upload(
            UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
            $type,
            $invoice
        );

        $fake->assertAttached(fn (Document $doc) => $doc->id === $document->id);
    }
}
```

`Documentable::fake()` swaps storage disks, the multipart driver, and package events. Validation, database writes, and versioning logic execute against the faked disk.

### Model factories

The package provides model factories for strict mode testing:
- `Document::factory()`: states `committed()` (default), `processing()`, `rejected()`, `latest()`, and `superseded()`.
- `DocumentType::factory()`: populates all required configuration columns.
- `StorageFile::factory()`: generates realistic SHA-256 hashes and sizes.
- `UploadSession::factory()`: states for lifecycle stages (`initiated()`, `uploaded()`, `attached()`, `expired()`, `aborted()`).

### Test assertions

`DocumentableFake` provides test assertions:

```php
$fake->assertUploaded(fn (UploadSession $session) => $session->id === $expectedId);
$fake->assertMultipartInitiated(fn (UploadSession $session) => $session->disk === 's3');
$fake->assertAttached(fn (Document $doc) => $doc->id === $document->id);
$fake->assertPurged(fn (Document $doc) => $doc->id === $document->id);
$fake->assertNothingUploaded();
```

## AI agent context (Laravel Boost)

The package ships `resources/boost/guidelines/laravel-documentable.blade.php` (a short overview: conventions, operational commands, pitfalls) and `resources/boost/skills/laravel-documentable/SKILL.md` (the detailed reference: upload transports, versioning semantics, the security checklist, artisan commands, troubleshooting). It also ships an upgrade skill for 3.x named `laravel-documentable-upgrade-v3` (`resources/boost/skills/laravel-documentable-upgrade-v3/SKILL.md`) to guide step-by-step migrations from 2.x.

If you have [Laravel Boost](https://github.com/laravel/boost) installed (`composer require laravel/boost --dev` and `php artisan boost:install`), Boost discovers these files in `vendor/` automatically.

Boost copies guidelines and skills into your project, so consuming applications hold snapshots that go stale when the package updates. Run `php artisan boost:update` after each package update to refresh them.

Without Boost, read the files in `vendor/madebyclowd/laravel-documentable/resources/boost/` or copy the skills to your AI agent's configuration directory.

## Upgrading

See [`UPGRADE.md`](UPGRADE.md) for full upgrade instructions and breaking changes.

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

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
