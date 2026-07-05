# Laravel Documentable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![Total Downloads](https://img.shields.io/packagist/dt/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Customizable, S3-compatible-first document storage for Laravel — content-addressable dedup,
composable versioning, multipart uploads, and orphan cleanup, without forcing you to adopt one
opinionated storage backend or admin UI.

## Features

- **Content-addressable storage** — sha256 dedup, reference-counted delete on purge (a physical
  object is only removed once no `Document` references it anymore).
- **Composable versioning + multi-document groups** — `allows_multiple` (how many independent slots
  per owner+type) and `requires_versioning` (keep history vs replace-in-place) compose
  independently, on any DB engine (no Postgres/SQLite-only partial indexes).
- **Two upload transports, one shared validation pipeline** — direct/presigned PUT for small files,
  multipart for large ones, both funneling through the same size/mime/security checks. Mime type is
  always server-detected from actual bytes, never trusted from the client.
- **Configurable multipart ETag strategy** — `client` (fewer round trips, needs bucket CORS) or
  `server-authoritative` (no CORS dependency) — integrity is always independently verified via
  sha256 regardless of which mode you pick.
- **Explicit lifecycle** — `pending`/`committed` status + `expires_at`, swept by a scheduled reaper
  that purges expired pending documents and aborts stale multipart sessions on the provider (not
  just the DB row).
- **Pluggable everything app-specific** — `AuthorizesDocumentAccess`, `ScansUploadedFile`,
  `ResolvesDedupScope`, `GeneratesStoragePath`, and the multipart backend itself
  (`MultipartUploadDriver`, resolved by disk driver — S3/R2/MinIO/Spaces ship out of the box) are all
  container-bound contracts, not forked code.
- **Domain events** — `DocumentUploaded`, `DocumentVersionSuperseded`, `DocumentDeleted`,
  `DocumentPurged`, `DocumentReassociated`, `MultipartUploadInitiated`, `MultipartUploadAborted`.
- **Optional audit trail** — `created_by`/`deleted_by` actor tracking and a per-access log table,
  both off by default.
- **HTTP routes shipped but optional** — mount the included controllers under your own
  prefix/middleware, or disable and build your own.

## Installation

```bash
composer require madebyclowd/laravel-documentable
php artisan documents:install
```

`documents:install` publishes the config and migrations, offers to run migrations, and walks you
through the `etag_strategy` and DocumentType-catalog choices (writing the answers into your
published config instead of leaving unconsidered defaults in place).

Manual/non-interactive equivalent:

```bash
php artisan vendor:publish --tag=documentable-config
php artisan vendor:publish --tag=documentable-migrations
php artisan migrate
```

## Basic usage

Attach the trait to any model you want to hold documents:

```php
use MadeByClowd\Documentable\Traits\Documentable;

class Invoice extends Model
{
    use Documentable;
}
```

Define a document type — code-first (recommended, git-versioned) or manage `document_types`
directly:

```php
// config/documentable.php
'types' => [
    'invoice' => [
        'name' => 'Invoice',
        'max_size_mb' => 10,
        'allowed_mimes' => ['application/pdf'],
        'disk' => 's3',
        'path_prefix' => 'invoices',
        'requires_versioning' => true,
        'allows_multiple' => false,
    ],
],
```

```bash
php artisan documents:sync-types
```

Upload:

```php
$service = app(\MadeByClowd\Documentable\Services\DocumentService::class);
$type = \MadeByClowd\Documentable\Models\DocumentType::where('code', 'invoice')->firstOrFail();

$document = $service->upload($request->file('file'), $type, $invoice);

$invoice->documents; // MorphMany<Document>
$service->getUrl($document, now()->addMinutes(5)); // presigned, temporary
```

## Uploading via the shipped HTTP API

Everything above calls `DocumentService` directly (your own controller, same request). The package
also ships routes under `/documents` (`config('documentable.load_routes')`, default `true`) if you'd
rather not write that controller yourself. Both the direct-PUT and multipart flows below assume the
client (browser/mobile app) talks to your bucket directly for the actual bytes — your app server
only ever handles small JSON requests, never the file body.

**Middleware**: these routes mount under `config('documentable.middleware')` (default `['api']`) —
no session, no auth, `$request->user()` is `null`. `php artisan documents:install` asks whether your
app is a session-based monolith (sets `['web', 'auth']`) or a separate API (stays `['api']`, wire
your own token guard in). If `$request->user()` is unexpectedly null in your authorizer or
`created_by`/`deleted_by`, this is why — see `docs/feedbacks/feedback.md` #1.

### Small files — presigned direct PUT (files under `multipart.threshold_bytes`, default 10MB)

1. Ask your server for a presigned URL:

   ```
   POST /documents/presigned
   { "document_type_id": "...", "filename": "invoice.pdf" }

   → { "url": "...", "headers": {...}, "path": "invoices/abc.pdf", "disk": "s3" }
   ```

2. Client `PUT`s the raw file bytes straight to `url` (with `headers` if any) — no auth, doesn't
   touch your app server.

3. Client tells your server it's done, including the sha256 it computed client-side:

   ```
   POST /documents/presigned/finalize
   {
     "path": "invoices/abc.pdf",
     "document_type_id": "...",
     "documentable_type": "App\\Models\\Invoice",
     "documentable_id": "42",
     "filename": "invoice.pdf",
     "expected_hash": "<sha256 hex over the file>"
   }

   → Document JSON, 201
   ```

   The server re-downloads and re-hashes the object and compares it to `expected_hash`; on a
   size/mime/hash mismatch it deletes the object and returns a validation error — you never end up
   with an orphaned blob.

### Large files — multipart (files at/above `multipart.threshold_bytes`)

**1. Initiate.** Creates the multipart session on the bucket plus a DB row scoped to `user_id` —
every later call for this session must supply the *same* `user_id` or it's rejected (this is the
ownership check, not decorative). If you omit `user_id`, the controller falls back to
`$request->user()->getAuthIdentifier()`.

```
POST /documents/multipart/initiate
{ "filename": "big.zip", "document_type_id": "...", "user_id": "..." }

→ { "upload_id": "...", "path": "invoices/xyz.zip", "disk": "s3" }
```

**2. Upload each part directly to the bucket.** For every part (1-indexed; S3 requires each part
≥5MB except the last):

```
POST /documents/multipart/part-url
{ "path": "...", "upload_id": "...", "part_number": 1, "document_type_id": "...", "user_id": "..." }

→ { "url": "..." }
```

Client `PUT`s that part's bytes to `url`. **Only if `etag_strategy = client`**, capture the `ETag`
response header from that PUT — you'll need it in step 3. Under the default
`etag_strategy = server-authoritative`, don't bother capturing it; the server re-derives everything
from the bucket's own `ListParts` at completion time. Repeat this step for every part.

**3. Complete.**

```
POST /documents/multipart/complete
{
  "path": "...", "upload_id": "...", "user_id": "...",
  "document_type_id": "...",
  "documentable_type": "App\\Models\\Invoice", "documentable_id": "42",
  "filename": "big.zip",
  "expected_hash": "<sha256 hex over the whole assembled file>",
  "parts": [{"PartNumber": 1, "ETag": "\"...\""}, {"PartNumber": 2, "ETag": "\"...\""}]
}

→ Document JSON, 201
```

`parts` is only read when `etag_strategy = client`; omit it under `server-authoritative`. The server
assembles the object, verifies integrity (native-checksum fast path or a full re-hash — transparent
either way), and creates the `Document`. Any failure deletes the assembled object server-side first.

**Abort**, if the client gives up partway (closed tab, network drop):

```
POST /documents/multipart/abort
{ "path": "...", "upload_id": "...", "document_type_id": "...", "user_id": "..." }
```

If nobody calls abort, `documents:clean-orphaned` (auto-scheduled) sweeps the session after
`multipart.session_ttl_hours` and aborts it on the bucket too — not just deleting the DB row.

### Same two flows without the shipped routes — direct service calls

```php
// Direct PUT:
$presigned = $service->createPresignedUpload($type, $filename);
// ...client PUTs to $presigned['url']...
$document = $service->finalizeDirectUpload($presigned['path'], $type, $invoice, $filename, $expectedHash);

// Multipart:
$session = $service->initiateMultipartUpload($filename, $type, $userId);
$url = $service->generatePartUploadUrl($session['path'], $session['upload_id'], $userId, $partNumber, $type);
// ...client PUTs to $url for each part...
$document = $service->completeMultipartUpload(
    $session['path'], $session['upload_id'], $userId, $type, $invoice, $filename,
    clientParts: null, expectedHash: $hash
);
```

## Frontend integration notes

The HTTP flows above are transport-agnostic, but a few things are the frontend's responsibility —
the package can't enforce them from the browser:

- **Pick the transport yourself.** The package does not auto-route based on size — that only
  happens server-side inside `DocumentService::upload()` for the *owned, streamed-through-your-app*
  path. If your frontend always calls the multipart endpoints regardless of file size, you lose the
  whole point of the direct-PUT path (best-practices.md §1: never force multipart on small files).
  Compare `file.size` against your app's configured `multipart.threshold_bytes` client-side (expose
  it via a config/meta endpoint, or just hardcode the same number your backend uses) and call
  `/documents/presigned` below it, `/documents/multipart/initiate` at/above it.
- **No dedup pre-check endpoint exists.** If you want a "skip upload entirely if this exact file
  already exists" handshake (client sends a hash manifest, server reports which are already stored),
  you have to build that endpoint yourself in your host app — it's not part of this package's shipped
  routes. Dedup still happens automatically server-side once the upload lands (same content →
  reused `StorageFile`, no duplicate object written) — you just don't get to skip the network
  transfer itself without a custom pre-check.
- **Responses are not wrapped.** Every shipped endpoint returns the raw JSON body directly
  (`{upload_id, path, disk}`, the `Document` object, etc.) — no `{status: ..., data: ...}` envelope.
  If your app's axios instance has a global response interceptor that unwraps a different shape,
  make sure these routes are excluded from it.
- **Chunk size:** S3 requires each part ≥5MB except the last. 5-10MB is a reasonable default; going
  much smaller multiplies your part-url round trips for no benefit.
- **`etag_strategy = server-authoritative` (the default) needs the client to report `{PartNumber}`
  only** — don't bother reading the `ETag` response header off each part PUT. Only capture it under
  `etag_strategy = client`.
- **Call abort on cancel.** If the user cancels or navigates away mid-upload, `POST
  /documents/multipart/abort` proactively instead of relying solely on the scheduled reaper —
  frees the bucket-side incomplete upload immediately instead of waiting out
  `multipart.session_ttl_hours`.
- **Retry failed part PUTs** a few times before giving up on the whole upload — a single dropped
  connection on one part shouldn't fail the entire file when the other parts already succeeded.
- **Hash client-side via `crypto.subtle.digest('SHA-256', await file.arrayBuffer())`** works fine for
  typical document sizes but loads the whole file into memory first — be aware of that ceiling for
  very large files; there's no incremental/streaming digest built into `SubtleCrypto`.
- **`documentable_type`/`documentable_id`** should be the real owning record's morph class + id
  (whatever `$model->getMorphClass()`/`getKey()` would return server-side) — not necessarily the
  authenticated user. The package's morph design is generic; don't narrow it to "always the user"
  unless that's actually your data model.

## Advanced usage

**Multiple independently-versioned slots per owner** (`allows_multiple = true`,
`requires_versioning = true` on the type):

```php
// Start a new independent slot (e.g. "attachment #2"):
$attachment2 = $service->upload($file, $type, $invoice);

// Add a new version *to that specific slot*:
$service->upload($newFile, $type, $invoice, documentGroupId: $attachment2->document_group_id);
```

**Detached upload, reassociated once the real owner exists:**

```php
$document = $service->uploadDetached($file, $type, pending: true, ttlHours: 24);
// ...
$service->reassociateDocument($document, $invoice);
$document->commit();
```

**Choosing an `etag_strategy`:** `server-authoritative` (default) needs no bucket CORS
configuration and works everywhere, at the cost of one extra `ListParts` call per multipart
completion. `client` saves that round trip but requires `ExposeHeaders: ["ETag"]` on your bucket's
CORS policy — only pick it if you control the bucket.

**Scoping dedup per tenant** instead of the default global-by-hash:

```php
class TenantScopedDedupScope implements \MadeByClowd\Documentable\Contracts\ResolvesDedupScope
{
    public function scopeKey(string $hash, ?Model $documentable): string
    {
        return ($documentable?->tenant_id ?? 'none').':'.$hash;
    }
}
```

```php
// config/documentable.php
'dedup' => ['scope_resolver' => TenantScopedDedupScope::class],
```

**Listening for events:**

```php
Event::listen(function (\MadeByClowd\Documentable\Events\DocumentUploaded $event) {
    GenerateThumbnail::dispatch($event->document);
});
```

## Artisan commands

| Command | Purpose |
|---|---|
| `documents:install` | Interactive installer (publish + configure). |
| `documents:make-authorizer {name=AppDocumentAuthorizer}` | Scaffold a starter `AuthorizesDocumentAccess` implementation in `app/Documentable`. |
| `documents:sync-types [--prune]` | Upsert `config('documentable.types')` into `document_types`. |
| `documents:list` | Table of registered types with usage counts. |
| `documents:verify [--repair]` | Detect (and optionally fix) `latest_marker`/`is_latest` drift. |
| `documents:clean-orphaned [--hours=N]` | Reaper — purges expired pending documents, aborts stale multipart sessions. Auto-scheduled. |
| `documents:configure-bucket-lifecycle {disk} [--days=3]` | Optional bucket-native `AbortIncompleteMultipartUpload` backstop. |

## Configuration

Full annotated file lives at [`config/documentable.php`](config/documentable.php). Key sections:

```php
'disk' => env('DOCUMENTABLE_DISK', 's3'),
'load_migrations' => true,
'load_routes' => true,
'middleware' => ['api'], // ['web', 'auth'] for a session-based monolith — see "Uploading via the shipped HTTP API"
'types' => [/* code-first DocumentType catalog, keyed by code */],
'multipart' => [
    'threshold_bytes' => 10 * 1024 * 1024,
    'etag_strategy' => 'server-authoritative', // or 'client'
    'part_upload_url_ttl' => '+1 hour',
    'session_ttl_hours' => 24,
    'use_native_checksum' => false, // optional S3 additional-checksums fast path
    'drivers' => ['s3' => S3MultipartDriver::class],
],
'lifecycle' => ['pending_ttl_hours' => 24, 'reaper_frequency' => 'hourly'],
'authorization' => ['resolver' => null], // bind AuthorizesDocumentAccess
'dedup' => ['scope_resolver' => null],   // bind ResolvesDedupScope
'security' => [
    'scanner' => null,                          // bind ScansUploadedFile
    'allowed_documentable_types' => null,        // see "Security" section below
],
'storage_path' => ['generator' => null], // bind GeneratesStoragePath
'disks' => [/* per-disk server_side_encryption / kms_key_id */],
'throttle' => 'documents', // named rate limiter for the shipped routes
'audit' => ['enabled' => false, 'access_log' => false],
```

## Security

Two things you should do before going to production, both flagged by the first real integrator's
feedback (`docs/feedbacks/feedback.md`):

1. **`documentable_type` allowlist.** `documentable_type` on every upload/finalize/complete request
   is resolved via `Relation::getMorphedModel()`. If your app hasn't called
   `Relation::enforceMorphMap()` (or `Relation::morphMap()`), an unmapped type is **rejected** by
   default — set `config('documentable.security.allowed_documentable_types')` to an explicit array
   of allowed FQCNs only if you need to accept unmapped types, and prefer a morph map instead where
   possible.
2. **`AuthorizesDocumentAccess`.** The default is `PermissiveDocumentAuthorizer` (allows everything).
   Bind a real implementation via `config('documentable.authorization.resolver')` before production
   use — `php artisan documents:make-authorizer` scaffolds a starting point.

Neither of these is optional in combination: an allowlisted/morph-mapped type with a permissive
authorizer still lets any caller attach/view/delete documents against any instance of that type.

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
