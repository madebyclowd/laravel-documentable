# Laravel Documentable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![Total Downloads](https://img.shields.io/packagist/dt/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![run-tests](https://img.shields.io/github/actions/workflow/status/madebyclowd/laravel-documentable/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/madebyclowd/laravel-documentable/actions/workflows/run-tests.yml)
[![security](https://img.shields.io/github/actions/workflow/status/madebyclowd/laravel-documentable/security.yml?branch=main&label=security&style=flat-square)](https://github.com/madebyclowd/laravel-documentable/actions/workflows/security.yml)
[![codecov](https://img.shields.io/codecov/c/github/madebyclowd/laravel-documentable?style=flat-square)](https://codecov.io/gh/madebyclowd/laravel-documentable)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Document storage for Laravel, built for S3-compatible buckets: content-addressable deduplication,
composable versioning, multipart uploads, and orphan cleanup. It does not force one storage backend
or admin UI on you.

It is the file-upload system your app needs, already built, so you do not have to write versioning,
deduplication, or secure uploads yourself.

## New here? Start with this section

If you have never used a package like this, read this section first. It explains what the package
does, walks through your first upload step by step, and defines the terms used later in this README.
If you already know S3 and multipart uploads, skip to [Features](#features).

### What does this package do?

Does your app let users upload files, such as invoices, photos, contracts, or avatars, and then
store, retrieve, and manage them? This package handles that:

- You add one line to any Eloquent model (`Invoice`, `User`, `Project`, ...) and it can hold documents.
- You define types of upload, such as `"invoice"` or `"profile-photo"`. A type is a set of rules:
  maximum file size, allowed file formats, and whether old versions are kept.
- The package stores files in an S3-compatible bucket (AWS S3, Cloudflare R2, MinIO, DigitalOcean
  Spaces), not on your app server's disk, which stops working once you run more than one server.
- It stores the same file only once, and it can keep a version history each time a file is replaced.

You do not need to know how S3 or multipart uploads work. The quick start below uses copy-paste
commands, and the [Glossary](#glossary) defines each term.

### 5-minute quick start

Follow these five steps in order. At the end you will have uploaded your first file. Each step is a
command or a short code snippet.

**1. Install the package.**

```bash
composer require madebyclowd/laravel-documentable
```

**2. Run the installer.** It asks a few questions and sets up the config file, the database tables,
and, if you want one, a starter security file:

```bash
php artisan documents:install
```

If you are unsure how to answer a question, press Enter to accept the first option shown. It is a
safe default for most apps.

**3. Let one of your models hold documents.** Pick a model you already have. This example uses
`Invoice`, and any model works the same way:

```bash
php artisan documents:attach-model Invoice
```

This adds `use Documentable;` to `app/Models/Invoice.php`. To do it yourself, add this:

```php
use MadeByClowd\Documentable\Traits\Documentable;

class Invoice extends Model
{
    use Documentable;
}
```

**4. Tell the package what kind of file you will upload.** Open `config/documentable.php`, find the
`'types'` array near the top, and add an entry:

```php
'types' => [
    'invoice-pdf' => [
        'name' => 'Invoice PDF',
        'max_size_mb' => 10,
        'allowed_mimes' => ['application/pdf'],
        'disk' => 's3', // whatever your bucket disk is called in config/filesystems.php
        'path_prefix' => 'invoices',
        'requires_versioning' => true,
        'allows_multiple' => false,
    ],
],
```

Then save that type to the database. Run this again whenever you change the array:

```bash
php artisan documents:sync-types
```

**5. Upload your first file** from any controller action:

```php
$service = app(\MadeByClowd\Documentable\Services\DocumentService::class);
$type = \MadeByClowd\Documentable\Models\DocumentType::where('code', 'invoice-pdf')->firstOrFail();

$document = $service->upload($request->file('file'), $type, $invoice);
```

`$document` is now saved and `$invoice->documents` lists it. Get a temporary link to view or download
it with:

```php
$service->getUrl($document, now()->addMinutes(5));
```

**Next steps.** The five steps run entirely in PHP you write in a controller. If your frontend
(React, Vue, a mobile app) should call this package over HTTP instead, including for large files that
skip your Laravel server, see
["Uploading via the shipped HTTP API"](#uploading-via-the-shipped-http-api).

### Glossary

Terms used in the rest of this README:

| Term | Meaning |
|---|---|
| Disk | Laravel's name for where files are stored (`config/filesystems.php`). This package needs an S3-compatible disk: AWS S3, or a compatible service such as Cloudflare R2, MinIO, or DigitalOcean Spaces. |
| DocumentType | A named kind of upload (for example "invoice" or "profile-photo") with its own rules: maximum size, allowed formats, and whether old versions are kept. |
| Documentable | The model a document belongs to, such as an `Invoice`, a `User`, or a `Project`. Any Eloquent model becomes documentable when you add the `Documentable` trait to it. |
| Dedup (deduplication) | If the same file is uploaded twice, the package stores the bytes once and links both uploads to that one stored file. |
| Versioning | Keeping the history of past uploads for the same slot, instead of discarding the old file when a new one replaces it. |
| Presigned URL / direct-PUT upload | A short-lived link that lets the browser send file bytes straight to your S3 bucket, so the file never passes through your Laravel app server. Used for smaller files. |
| Multipart upload | The technique for large files. The file is split into parts, each part is uploaded separately (often in parallel), and the bucket assembles them. It copes with slow or unreliable connections better than one large upload. |
| ETag | A checksum the storage provider returns for each uploaded part. The package uses it to confirm that a multipart upload assembled correctly. |
| Morph type / `documentable_type` | Laravel's name for which kind of model a document is attached to (for example `App\Models\Invoice`). It appears in the HTTP request examples below. |
| Authorizer | The code that decides who may upload, view, or delete a document. The package ships a permissive default that allows everyone, plus a command that generates a real one. See [Security](#security). |

## Features

- **Content-addressable storage.** Deduplication by SHA-256, with reference-counted deletes on purge: a
  stored object is removed only when no `Document` references it.
- **Composable versioning and multi-document groups.** `allows_multiple` (how many independent slots
  per owner and type) and `requires_versioning` (keep history or replace in place) work independently
  of each other, on any database engine. The package uses no Postgres-only or SQLite-only partial
  indexes.
- **Two upload transports, one validation pipeline.** Direct presigned PUT for small files and
  multipart for large ones both go through the same size, mime, and security checks. The mime type
  always comes from the actual bytes, never from the client.
- **Configurable multipart ETag strategy.** `client` needs fewer round trips but needs bucket CORS.
  `server-authoritative` has no CORS dependency. The server verifies integrity with SHA-256 in both
  modes.
- **Explicit lifecycle.** A `pending` or `committed` status plus `expires_at`. A scheduled reaper
  purges expired pending documents and aborts stale multipart sessions on the provider, not only the
  database row.
- **Pluggable app-specific parts.** `AuthorizesDocumentAccess`, `ScansUploadedFile`,
  `ResolvesDedupScope`, `GeneratesStoragePath`, and the multipart backend (`MultipartUploadDriver`,
  chosen by disk driver; S3, R2, MinIO, and Spaces ship with the package) are container-bound
  contracts. You bind your own implementation and do not fork the code.
- **Domain events.** `DocumentUploaded`, `DocumentVersionSuperseded`, `DocumentDeleted`,
  `DocumentPurged`, `DocumentReassociated`, `MultipartUploadInitiated`, `MultipartUploadAborted`.
- **Optional audit trail.** `created_by` and `deleted_by` actor tracking and a per-access log table.
  Both are off by default.
- **Optional HTTP routes.** Mount the included controllers under your own prefix and middleware, or
  disable them and build your own.

## Installation

Requires PHP 8.3 or newer, `ext-fileinfo`, and Laravel 12 or 13. Tested on SQLite, MySQL and PostgreSQL.

```bash
composer require madebyclowd/laravel-documentable
php artisan documents:install
```

`documents:install` publishes the config and migrations, offers to run the migrations, and asks you
to choose the app shape, the `etag_strategy`, and the DocumentType catalog. It writes your answers
into the published config instead of leaving defaults you did not consider.

For a scripted or CI install, pass `--shape`, `--etag-strategy`, and `--types` to skip the prompts:

```bash
php artisan documents:install --no-interaction \
    --shape=monolith \
    --etag-strategy=server-authoritative \
    --types=code-first
```

Running with `--no-interaction` and without `--shape` prints a warning and falls back to
`separate-api` (`middleware => ['api']`, no session or auth, so `$request->user()` stays `null`).
The warning keeps that fallback from happening silently. `--etag-strategy` and `--types` have no
comparable security consequence, so they default silently under `--no-interaction` when omitted.

The manual, fully non-interactive equivalent, with no installer:

```bash
php artisan vendor:publish --tag=documentable-config
php artisan vendor:publish --tag=documentable-migrations
php artisan migrate
```

## Uploading via the shipped HTTP API

The steps above call `DocumentService` directly from your own controller, in the same request. The
package also ships routes under `/documents` (`config('documentable.load_routes')`, default `true`)
if you would rather not write that controller. In both flows below, the client (browser or mobile
app) sends the file bytes straight to your bucket. Your app server handles only small JSON requests,
never the file body.

**Middleware.** These routes mount under `config('documentable.middleware')` (default `['api']`),
which has no session and no auth, so `$request->user()` is `null`. `php artisan documents:install`
asks whether your app is a session-based monolith (it sets `['web', 'auth']`) or a separate API (it
stays `['api']`, and you wire in your own token guard). If `$request->user()` is unexpectedly `null`
in your authorizer or in `created_by` or `deleted_by`, this is the cause. See the Troubleshooting
section of this package's Boost skill, or re-run `documents:install` and pick "monolith".

### Listing document types

```
GET /documents/types
```

A read-only catalog of active `DocumentType`s with `id`, `code`, `name`, `max_size_mb`,
`allowed_mimes`, `allows_multiple`, and `requires_versioning`, without pagination. It lets the
frontend fetch a `document_type_id` without database access or the CLI-only
`php artisan documents:list`. Soft-deleted (deactivated) types are excluded. The list contains only
the types for which the authorizer's `canUpload($user, $type, null)` returns true.

### Temporary URLs

```
GET /documents/{document}/url?disposition=inline|attachment&expires_in_minutes=5
```

`disposition` must be `inline` (default) or `attachment`. `expires_in_minutes` is an integer from 1
up to `documentable.url.max_ttl_minutes` (default 1440; the default request is 5). Any other value
returns 422. A `pending` document past its `expires_at` returns 404.

### Listing an owner's documents

```
GET /documents?documentable_type=...&documentable_id=...[&document_type_id=...][&page=1]
```

Returns the latest document in each slot for that owner, grouped as
`{document_type_id: {document_group_id: document}}` (`storageFile` eager-loaded, the same shape as
the upload endpoints). The package applies `canView()` to each document after the query, so a custom
authorizer that denies some documents removes only those. Pagination
(`config('documentable.listing.per_page')`, default 50) runs over the filtered result:

```json
{
  "data": { "<document_type_id>": { "<document_group_id>": { "id": "...", "storage_file": {...} } } },
  "meta": { "current_page": 1, "per_page": 50, "total": 3, "last_page": 1 }
}
```

### Small files with a presigned direct PUT

This flow is for files under `multipart.threshold_bytes` (default 10MB).

1. Ask your server for a presigned URL:

   ```
   POST /documents/presigned
   { "document_type_id": "...", "filename": "invoice.pdf" }

   → { "url": "...", "headers": {...}, "path": "invoices/abc.pdf", "disk": "s3" }
   ```

2. The client sends a `PUT` with the raw file bytes straight to `url`, with `headers` if any. The
   request needs no auth and does not touch your app server.

3. The client tells your server it is done and sends the SHA-256 it computed:

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

   The server downloads the object again, hashes it, and compares the hash with `expected_hash`. On a
   size, mime, or hash mismatch it returns a validation error. It does not delete the object. On this
   transport `path` is only a claim by the client, so deleting it could destroy a file the caller
   never uploaded. A rejected upload stays in the bucket until your bucket lifecycle rule expires it,
   so add an expiration rule for your upload prefixes. The server refuses to finalize a path that
   already backs a stored file. `path` must be a relative key: no leading `/`, no `..` segment, no
   backslash, and at most 1,024 characters.

### Large files with multipart uploads

This flow is for files at or above `multipart.threshold_bytes`.

**1. Initiate.** This creates the multipart session on the bucket and a database row scoped to
`user_id`. Every later call for this session must send the same `user_id` or the server rejects it.
That is the ownership check. The owner is `$request->user()->getAuthIdentifier()`. The server ignores
a `user_id` in the request body unless `documentable.security.allow_guest_user_id` is `true`. Avoid
that setting: anyone who can reach the route can then name any owner, so use it only for a guest or
device flow you protect another way. `part-url`, `parts`, `status`, and `abort` call
`AuthorizesDocumentAccess::canUpload()` for the session's type, as `initiate` does.

```
POST /documents/multipart/initiate
{ "filename": "big.zip", "document_type_id": "...", "user_id": "..." }

→ { "upload_id": "...", "path": "invoices/xyz.zip", "disk": "s3" }
```

**2. Upload each part directly to the bucket.** For every part (numbered from 1; S3 requires each
part to be at least 5MB except the last):

```
POST /documents/multipart/part-url
{ "path": "...", "upload_id": "...", "part_number": 1, "document_type_id": "...", "user_id": "..." }

→ { "url": "..." }
```

The client sends a `PUT` with that part's bytes to `url`. Only with `etag_strategy = client`, keep the
`ETag` response header from that PUT for step 3. Under the default
`etag_strategy = server-authoritative` you can ignore it, because the server reads the part list from
the bucket's own `ListParts` when it completes the upload. Repeat this step for every part.

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

The server reads `parts` only when `etag_strategy = client`; omit it under `server-authoritative`. The
server assembles the object, verifies its integrity, and creates the `Document`. It verifies by
streaming the object and hashing it, because no provider can attest a full-file SHA-256. If anything
fails, the server deletes the assembled object first.

**Abort** if the client gives up partway (closed tab, network drop):

```
POST /documents/multipart/abort
{ "path": "...", "upload_id": "...", "document_type_id": "...", "user_id": "..." }
```

If nobody calls abort, `documents:clean-orphaned` (scheduled automatically) sweeps the session after
`multipart.session_ttl_hours` and aborts it on the bucket as well as deleting the database row.

**Resuming after a dropped connection.** Before you re-upload anything, check what landed:

```
GET /documents/multipart/status?path=...&upload_id=...&user_id=...
→ { "exists": true, "expires_at": "2026-01-01T00:00:00+00:00", "disk": "s3" }
```

`exists: false` (still a `200`, not an error) means the session is gone: reaped, aborted, or never
created. Start a new `initiate` instead of retrying against it. If the session exists, compare your
local part tracking with the bucket's own record before you resume:

```
GET /documents/multipart/parts?path=...&upload_id=...&document_type_id=...&user_id=...
→ { "parts": [{"PartNumber": 1, "ETag": "\"...\""}] }
```

Re-upload only the part numbers missing from this list. Re-uploading a part that is already there is
always safe (the last write wins on S3, R2, MinIO, and Spaces; the `MultipartUploadDriver` interface
docblock lists the guarantee for each provider, including one R2 edge case), but comparing first
avoids a wasted transfer.

### Disk

Set `'throw' => true` on the disk your document types use (`config/filesystems.php`). Laravel's
default for `s3` is `false`, which reports a failed write or delete as `false` instead of an
exception. The package checks those results where it can, but any other code touching the disk
silently loses them. `documents:sync-types` warns when a type uses a disk without it.

### Deploy

Run `php artisan documents:sync-types` on every deploy, next to `migrate`. It is idempotent, reports
what it created and updated, and lists types that exist only in the database. `php artisan about`
shows whether the config and the table agree. Run `documents:configure-bucket-lifecycle` once per
bucket as a fallback for abandoned uploads. The shipped routes use a rate limiter named by
`documentable.throttle`. If you define none, the package registers one that does not limit anything,
so define `RateLimiter::for('documents', ...)` yourself.

### Provider notes

This table shows what differs between S3-compatible providers for multipart uploads. "Verified" means
checked against a real bucket while preparing this release. The rest is the provider's documented
behavior, so test it before you rely on it.

| | AWS S3 | Cloudflare R2 | MinIO / RustFS | Backblaze B2 | DO Spaces |
|---|---|---|---|---|---|
| Minimum part size (except the last) | 5 MiB | 5 MiB | 5 MiB | 5 MB | 5 MiB |
| Maximum parts | 10,000 | 10,000 | 10,000 | 10,000 | 10,000 |
| All parts except the last must be the same size | no | yes (verified): use one fixed part size | no | no | no |
| Upload id length | short | 343 characters (verified) | short | unverified | unverified |
| `UploadPart` with `x-amz-checksum-sha256` | unverified | rejected, `NotImplemented` (verified); `x-amz-checksum-crc32` is accepted | accepted (verified on RustFS) | unverified | unverified |
| Single `PutObject` with `x-amz-checksum-sha256`: the provider rejects a body that does not match | unverified | yes, `BadDigest` (verified), also through a presigned URL that signs the header | yes (verified on RustFS) | unverified | unverified |
| `HeadObject` with `ChecksumMode=ENABLED` returns the stored SHA-256 | unverified | yes (verified); `GetObjectAttributes` is `NotImplemented` | yes (verified on RustFS) | unverified | unverified |
| Accepts the AWS SDK's default checksum headers on `PutObject` (aws-sdk-php 3.402) | yes | yes (verified) | yes (verified on RustFS) | unverified | unverified |
| Browser needs `ExposeHeaders: ETag` in CORS (`etag_strategy = client`) | yes | yes (verified works) | yes | yes | yes |
| Unfinished uploads expire on their own | only with a lifecycle rule | only with a lifecycle rule | only with a lifecycle rule | only with a lifecycle rule | unverified |

`php artisan documents:doctor {disk}` checks the rows you depend on against your own bucket before you
deploy. The package presigns with `request_checksum_calculation` set to `when_required` whatever the
disk's own setting, so a provider that rejects the SDK's default checksum headers still accepts its
URLs.

Upload ids longer than 255 characters need the `2026_10_24_000006` and `000007` migrations from this
release (`php artisan migrate`). They widen `multipart_uploads.path`, `multipart_uploads.upload_id`
and `storage_files.path` to `text`, and they are safe to run on a table you already widened yourself.

### Behind a proxy or on a public domain

Set `presign_endpoint` on a disk when the browser reaches storage at a different address than your
servers do. A separate S3 client signs every URL the browser uses (downloads, single PUTs, and
multipart part PUTs) against that address. Signing is a local computation, so it costs no network
call:

```php
// config/documentable.php
'disks' => [
    's3' => [
        'presign_endpoint' => 'https://storage.example.com',   // what the browser connects to
        'presign_use_path_style' => false,                      // optional; defaults to the disk's own setting
    ],
],
```

The signature covers that host, so it stays valid without the proxy having to forward the original
`Host` header. Server-side reads, hashing, and deletes keep using the disk's own endpoint. Without
`presign_endpoint`, the disk's own client signs.

Multipart uploads use one fixed part size for every part but the last, which Cloudflare R2 requires.
When the client declares the file size at initiate, the response carries `part_size`.
`PartSizePlanner` computes it as `max(5 MiB, ceil(size / 10,000))`, rounded up to a whole MiB.
`CreateMultipartUpload` sends no filename metadata and no forced `Content-Type`. Set
`multipart.object_metadata` or `multipart.content_type` if you want them.

`php artisan documents:clean-orphaned --bucket` lists objects under your document types' path
prefixes that no stored file or live upload session points at. It is a dry run; add `--apply` to
delete. On a bucket with versioning on, a delete only adds a delete marker, so the command warns you
to add a `NoncurrentVersionExpiration` lifecycle rule.

### The same two flows through direct service calls

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

## Reading documents

### Model accessors and scopes

`Document` models provide accessors for common file properties:

```php
$document->filename;   // Client filename (e.g. "report.pdf")
$document->size;       // File size in bytes from the storage file
$document->mime;       // MIME type from the storage file (e.g. "application/pdf")
$document->extension;  // Lowercase extension from filename ("pdf"), or "" when none
$document->temporaryUrl(); // Presigned download URL delegating to DocumentService::getUrl()
```

If the `storageFile` relation is not already loaded, accessing `size` or `mime` loads it lazily once. If the backing storage file record is missing, a `LogicException` is thrown.

Under `Model::shouldBeStrict()`, Eloquent prevents accessing missing attributes on models. Defined accessors above are always safe to read, whereas accessing undefined properties throws:

```php
// Under Model::shouldBeStrict():
$document->filename;           // Safe (defined accessor)
$document->undefined_property; // Throws Illuminate\Database\Eloquent\MissingAttributeException
```

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
// The latest document of a type, in one query
$avatar = $user->documentOfType('avatar')->first();

// MorphMany relation to all documents of a type
$invoices = $user->documentsOfType('invoice')->get();

// Eager load for many owners in one extra query. Only the latest document of that type lands in `documents`:
$users = User::withDocumentOfType('avatar')->get();
```

Both helpers respect string `key_type` configurations and integer primary key owners.

### Reading contents and streams

Read stored bytes safely without bypassing the service or directly accessing storage disks:

```php
// Open a readable stream (PHP resource)
$stream = $documentService->readStream($document);
$firstChunk = fread($stream, 8192);
fclose($stream);

// Read entire contents into a string with a memory limit guard (default 10MB)
$contents = $documentService->readContents($document, maxBytes: 5 * 1024 * 1024);
```

Both methods refuse non-committed documents (`DocumentNotReady`), throw `DocumentTooLargeToRead` when exceeding the byte limit, and record an access log entry with action `read` when `documentable.audit.access_log` is enabled.

### DocumentResource & DocumentCollection

Use `DocumentResource` to serialize documents with `id`, `filename`, `size`, `mime`, `version`, and `type`.

To prevent accidental generation of presigned URLs during bulk listings, presigned `url` generation is **off by default**. Opt in explicitly with `->withUrl()`:

```php
use MadeByClowd\Documentable\Http\Resources\DocumentResource;

// Presigned URL omitted by default:
return new DocumentResource($document);

// Presigned URL included:
return (new DocumentResource($document))->withUrl();

// Collections:
return DocumentResource::collection($documents)->withUrl();
```

## Download links

Stable HTTP endpoints for viewing and downloading documents (e.g. for exports or emails):

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
- The `?expires_in_minutes=` parameter is ignored on these routes: URL lifetimes always use the configured default TTL.
- Generates access audit log entries when `documentable.audit.access_log` is enabled.

### Custom URL options & modifiers

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

`UrlOptions` checks its own disposition and content type when it is built, so a modifier cannot return options that break the allowlist.

## Frontend integration notes

The HTTP flows above work with any transport, but the package cannot enforce a few things from the
browser, so your frontend has to handle them:

- **Pick the transport yourself.** The package does not route by size. Only `DocumentService::upload()`
  does that, server-side, for the owned upload that streams through your app. If your frontend always
  calls the multipart endpoints regardless of file size, you lose the point of the direct-PUT path, so
  do not force multipart on small files. Compare `file.size` with your app's
  `multipart.threshold_bytes` on the client (expose it through a config or meta endpoint, or hardcode
  the number your backend uses). Call `/documents/presigned` below the threshold and
  `/documents/multipart/initiate` at or above it.
- **Proof-of-possession handshake.** To skip re-uploading an existing file without exposing a hash
  oracle, the client can use `POST /documents/handshake` and `POST /documents/handshake/answer`. If
  the server already holds the bytes under the current tenant scope, it issues a challenge with random
  byte ranges, and the client must prove it holds those bytes before the server grants an upload
  session. If the file is not stored, the server returns a decoy challenge with an identical
  structure, so callers cannot tell whether a blob exists.
- **Responses are not wrapped.** Every shipped endpoint returns the raw JSON body (`{upload_id, path,
  disk}`, the `Document` object, and so on), with no `{status: ..., data: ...}` envelope. If your
  app's axios instance has a global response interceptor that unwraps a different shape, exclude these
  routes from it.
- **Chunk size.** S3 requires each part to be at least 5MB except the last. 5 to 10MB is a reasonable
  default. Much smaller parts multiply your part-url round trips for no benefit.
- **ETag.** Capture the `ETag` response header only under `etag_strategy = client`. Step 2 of the
  multipart walkthrough above says when it matters.
- **Call abort on cancel.** If the user cancels or leaves mid-upload, call
  `POST /documents/multipart/abort` right away instead of waiting for the scheduled reaper. It frees
  the incomplete upload on the bucket immediately, not after `multipart.session_ttl_hours`.
- **Retry failed part PUTs.** Retry a few times before you give up on the whole upload. One dropped
  connection on one part should not fail the file when the other parts succeeded.
- **Hash on the client.** `crypto.subtle.digest('SHA-256', await file.arrayBuffer())` works for
  typical document sizes, but it loads the whole file into memory first. `SubtleCrypto` has no
  incremental digest, so very large files hit that limit.
- **`documentable_type` and `documentable_id`.** Send the real owning record's morph class and id
  (what `$model->getMorphClass()` and `getKey()` return server-side). That is not necessarily the
  authenticated user. The package's morph design is generic, so do not narrow it to the user unless
  that is your data model.

## Advanced usage

**Owners with integer, uuid or ulid keys.** `documents.documentable_id` is a string column, and
`documents()` sends the owner key as a string everywhere it meets that column. So `$owner->documents`,
`Owner::with('documents')`, `whereHas('documents', ...)` and `withCount('documents')` all work on
PostgreSQL for an owner with an integer primary key. You no longer need an `idAsString` accessor or
`$keyType = 'string'` on such a model, though keeping them does no harm. One cost: on PostgreSQL,
`whereHas` and `withCount` cast the owner key for the comparison, which cannot use an index on
`documentable_id` until a later major version types that column.

**Multiple independently versioned slots per owner.** Set `allows_multiple = true` and
`requires_versioning = true` on the type:

```php
// Start a new independent slot (e.g. "attachment #2"):
$attachment2 = $service->upload($file, $type, $invoice);

// Add a new version *to that specific slot*:
$service->upload($newFile, $type, $invoice, documentGroupId: $attachment2->document_group_id);
```

**Attaching an existing stored file (deduplication seam).**

```php
use MadeByClowd\Documentable\ValueObjects\AttachOptions;

$newDoc = $service->attachStorageFile(
    $storageFile,
    $type,
    $owner,
    'client_name.pdf',
    new AttachOptions(metadata: ['source' => 'import'])
);
```

The caller is responsible for having proven the right to reuse the file (the deduplication handshake does this). This is the supported seam replacing subclassing.

**Detached upload, reassociated once the real owner exists.**

```php
$document = $service->uploadDetached($file, $type, pending: true, ttlHours: 24);
// ...
$service->reassociateDocument($document, $invoice);
$document->commit();
```

Reassociating follows the same slot rules as an upload. If `$invoice` already holds a latest document of
the same single-slot type, the moved document becomes the next version of that slot (or replaces it, for a
type without `requires_versioning`), so the owner never has two "latest" documents. A multi-slot type keeps
the moved document in its own slot.

**Purging.** `purge()` removes the document row, then the stored file once no other document uses it. If
the disk cannot delete the file (a disk with `'throw' => false`, which is Laravel's default for `s3`,
reports that as `false`), `purge()` returns `false`, logs an error and keeps the `StorageFile` row as the
record of the orphaned object. `php artisan documents:clean-orphaned --storage-files` retries those. Set
`'throw' => true` on the documents disk so other failed writes and deletes raise instead of passing quietly;
`documents:sync-types` warns when a type uses a disk without it.

**Choosing an `etag_strategy`.** `server-authoritative` (the default) needs no bucket CORS configuration
and works everywhere, at the cost of one extra `ListParts` call per multipart completion. `client` saves
that round trip but requires `ExposeHeaders: ["ETag"]` on your bucket's CORS policy, so pick it only if you
control the bucket.

**Bucket CORS.** Both the direct-PUT and the multipart part-upload flows have the browser `PUT` bytes
straight to the bucket, cross-origin from your app. The bucket must allow `PUT` from your app's origin and,
under `etag_strategy = client`, expose `ExposeHeaders: ["ETag"]`.
`php artisan documents:configure-bucket-cors {disk} --origin=https://your-app.example.com` applies this,
and `--verify` then runs a live presigned-PUT smoke test.

### Deduplication scoping and proof of possession

#### The hash oracle

Naive content-addressable storage keys deduplication on the raw SHA-256 hash across the whole system.
That creates a hash oracle: someone who learns another user's or tenant's file hash (from a public URL, a
metadata leak, or a known invoice pattern) can attach that file to their own records without having the
bytes.

The package defends against this in two ways:

1. Dedup scoping, with `TypeAndTenantScope` by default. Stored files are scoped by document type and
   tenant identifier, so deduplication never crosses tenants or types unless you opt in.
2. A proof-of-possession handshake. A caller that wants to skip uploading an existing blob must first
   prove it has the bytes, by answering a challenge over random byte ranges, before the server grants an
   upload session.

#### Dedup scope resolvers and trade-offs

Set the active resolver in `config/documentable.php` under `'dedup' => ['scope_resolver' => ...]`.

- `TypeAndTenantScope` (the default) derives the key `sha256("{$typeId}|{$tenantId}|{$contentHash}")`.
  - It resolves the tenant id through the `MadeByClowd\Documentable\Contracts\HasDedupTenant` contract on
    the owner model, or through a callback you register in a service provider with
    `TypeAndTenantScope::resolveTenantUsing(fn (?Model $owner, ?Authenticatable $user, DocumentType $type): ?string => ...)`.
    With no tenant, the key still includes the document type.
  - Tenants and document types stay fully isolated. If tenant A and tenant B upload the same document,
    each keeps its own `StorageFile`. Deduplication happens only within one tenant and one document type.
  - A client cannot derive the internal dedup key from the content hash alone.

- `GlobalDedupScope` is the opt-in for global deduplication. It uses the raw content hash as the key
  across all tenants and types:
  ```php
  // config/documentable.php
  'dedup' => [
      'scope_resolver' => \MadeByClowd\Documentable\Defaults\GlobalDedupScope::class,
  ],
  ```
  - It saves the most storage, because duplicate files anywhere in the system share one object.
  - Pair it with the proof-of-possession handshake so callers cannot claim storage files they do not
    hold.

- `OwnerScopedDedupScope` restricts deduplication to one owner model instance
  (`sha256("{$ownerMorphClass}|{$ownerId}|{$contentHash}")`).

- For a custom resolver, implement `MadeByClowd\Documentable\Contracts\ResolvesDedupScope`:
  ```php
  use Illuminate\Contracts\Auth\Authenticatable;
  use Illuminate\Database\Eloquent\Model;
  use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
  use MadeByClowd\Documentable\Models\DocumentType;

  class CustomDedupScope implements ResolvesDedupScope
  {
      public function scopeKey(string $hash, DocumentType $type, ?Authenticatable $user, ?Model $owner): string
      {
          $tenantId = $owner?->organization_id ?? 'global';
          return hash('sha256', "{$type->id}|{$tenantId}|{$hash}");
      }
  }
  ```

#### Proof-of-possession handshake endpoints

Both endpoints sit behind the configured `canInitiateUpload` gate:

1. `POST /documents/handshake`
   - Body: `{"type": "invoice", "hash": "<sha256-hex>", "size": 1048576}`, plus `documentable_type` and `documentable_id` when the dedup scope depends on the owner (a tenant, say). Send the same owner you will attach to.
   - Response (hit or miss): `{"challenge_id": "...", "nonce": "...", "ranges": [{"offset": 1024, "length": 4096}, ...]}`
   - A hit needs the exact stored size. A different `size` is a miss.
   - Decoy challenges: a miss returns the same JSON structure with random ranges, to prevent timing and enumeration attacks. The client cannot tell whether the server holds the file without answering the challenge. Each miss writes one short-lived `dedup_challenges` row, which the rate limiter bounds and `documents:clean-orphaned` prunes.

2. `POST /documents/handshake/answer`
   - Body: `{"challenge_id": "...", "proof": "<sha256-hex>", "filename": "optional original name"}`
   - Proof computation: `proof = hash('sha256', $nonce . $rangeBytes1 . $rangeBytes2 ...)`, where `$nonce` is the hex string exactly as sent, not decoded (see the reference implementation `RangeProof::computeFromBytes`).
   - The server reads only the challenged ranges from the stored object (one ranged GET on S3-compatible disks), re-checks `canInitiateUpload`, and consumes the challenge atomically. Only the user the challenge was issued to can answer it.
   - On success it returns an `UploadSession` in `Uploaded` status, ready to attach through `/documents/attach`.
   - On failure (a wrong proof or a decoy challenge) it increments the attempt counter and returns HTTP 422. Three wrong attempts invalidate the challenge.

To listen for events:

```php
Event::listen(function (\MadeByClowd\Documentable\Events\DocumentUploaded $event) {
    GenerateThumbnail::dispatch($event->document);
});
```

The document events (`DocumentUploaded`, `DocumentDeleted`, `DocumentPurged`, `DocumentReassociated`,
`DocumentVersionSuperseded`, and the multipart ones) implement `ShouldDispatchAfterCommit`. Outside a
transaction they fire immediately. Inside one, including your own transaction around a call to the
service, they wait for the outermost commit and are dropped if it rolls back. A listener therefore always
sees the committed row, but it can no longer abort the surrounding transaction by throwing. Queued
listeners should implement `ShouldQueueAfterCommit` (or set `public $afterCommit = true`). Jobs you
dispatch from a listener should set `$afterCommit` too, so the queue never picks up work before the row is
visible.

## Artisan commands

| Command | Purpose |
|---|---|
| `documents:install` | Interactive installer (publish and configure). |
| `documents:make-authorizer {name=AppDocumentAuthorizer}` | Scaffold a starter `AuthorizesDocumentAccess` implementation in `app/Documentable`. |
| `documents:attach-model {model}` | Add `use Documentable;` (and its import) to an existing model. |
| `documents:sync-types [--prune]` | Upsert `config('documentable.types')` into `document_types`. |
| `documents:list` | Table of registered types with usage counts. |
| `documents:verify [--repair]` | Detect (and optionally fix) `latest_marker`/`is_latest` drift. |
| `documents:clean-orphaned [--hours=N] [--storage-files] [--bucket] [--apply]` | The reaper. It purges expired pending documents and aborts stale multipart sessions. The command removes a session the provider no longer knows. A session that fails is logged, kept for the next run, and makes the command exit non-zero. `--storage-files` retries deleting unreferenced stored files. `--bucket` sweeps orphaned objects directly in the S3 bucket under DocumentType prefixes (dry run by default, `--apply` to delete). The scheduler runs it without flags. |
| `documents:configure-bucket-lifecycle {disk} [--days=3]` | Configure bucket lifecycle rules (multipart abort and doctor scratch expiration), preserving other rules. |
| `documents:configure-bucket-cors {disk} --origin=... [--verify]` | Apply or verify the bucket CORS policy that direct-PUT and multipart part uploads need. |
| `documents:doctor {disk}` | Run 11 probes against the bucket and the S3 client configuration to check permissions and compatibility. |

## AI agent context (Laravel Boost)

The package ships `resources/boost/guidelines/laravel-documentable.blade.php` (a short overview:
conventions, operational commands, pitfalls) and `resources/boost/skills/laravel-documentable/SKILL.md`
(the detailed reference: upload transports, versioning semantics, the security checklist, every artisan
command, troubleshooting). If you have [Laravel Boost](https://github.com/laravel/boost) installed
(`composer require laravel/boost --dev` and `php artisan boost:install`), Boost discovers both files in
`vendor/` automatically, and you run nothing in this package. Boost chooses the install location for each
agent (its `ClaudeCode` agent class targets `.claude/skills/`, and other supported agents have their own
conventions). This package does not hardcode a directory for that.

Boost copies these files into your project, so the copy goes stale when the package changes. Run
`php artisan boost:update` after every `composer update madebyclowd/laravel-documentable`. Since 2.1.3 the
guideline file is named `laravel-documentable.blade.php` after the package, so a project that installed an
older version shows the guideline under a new heading after the update.

Without Boost, read the files in `vendor/madebyclowd/laravel-documentable/resources/boost/` and copy the
skill to wherever your AI tool expects it (Claude Code: `.claude/skills/laravel-documentable/SKILL.md` for
a project, or `~/.claude/skills/` for personal use across projects).

## Testing your app

The package provides testing tools so application tests can exercise document workflows without real S3 buckets or external network calls:

```php
use Illuminate\Http\UploadedFile;
use MadeByClowd\Documentable\Documentable;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;

public function test_user_can_upload_invoice(): void
{
    // Fake storage disks, the multipart driver, and package domain events
    $fake = Documentable::fake();

    $type = DocumentType::factory()->create([
        'code' => 'INVOICE',
        'disk' => 's3',
    ]);
    $user = User::factory()->create();

    $service = app(DocumentService::class);
    $document = $service->upload(
        UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
        $type,
        $user
    );

    // Assert that the upload was completed and attached
    $fake->assertAttached(fn (Document $doc) => $doc->id === $document->id);
}
```

`Documentable::fake()` fakes storage disks, the multipart driver, and package events (your own listeners for those events do not run, listeners for other events do); it does not fake `DocumentService` itself. All validation, database writes, and versioning logic run for real against the faked disk.

### Model factories

The package provides model factories for setting up valid test state under `Model::shouldBeStrict()`:

- `Document::factory()`: states `committed()` (default), `processing()`, `rejected()`, `latest()`, and `superseded()`.
- `DocumentType::factory()`: sets all database columns required for strict mode.
- `StorageFile::factory()`: creates realistic SHA-256 hashes and consistent sizes.
- `UploadSession::factory()`: states for each lifecycle stage (`initiated()`, `uploaded()`, `attached()`, `expired()`, `aborted()`).

### Test assertions

`DocumentableFake` provides assertions built on observable facts:

```php
$fake->assertUploaded(fn (UploadSession $session) => ...);         // An upload session in status 'uploaded' exists
$fake->assertMultipartInitiated(fn (UploadSession $session) => ...); // A multipart upload was started
$fake->assertAttached(fn (Document $doc) => ...);    // A DocumentUploaded event was dispatched
$fake->assertPurged(fn (Document $doc) => ...);      // A DocumentPurged event was dispatched
$fake->assertNothingUploaded();                      // No upload sessions and no documents exist
```

## Configuration

The full annotated file is [`config/documentable.php`](config/documentable.php). Key sections:

```php
'disk' => env('DOCUMENTABLE_DISK', 's3'),
'load_migrations' => true,
'load_routes' => true,
'middleware' => ['api'], // ['web', 'auth'] for a session-based monolith; see "Uploading via the shipped HTTP API"
'types' => [/* code-first DocumentType catalog, keyed by code */],
'multipart' => [
    'threshold_bytes' => 10 * 1024 * 1024,
    'etag_strategy' => 'server-authoritative', // or 'client'
    'part_upload_url_ttl' => '+1 hour',
    'session_ttl_hours' => 24,
    'drivers' => ['s3' => S3MultipartDriver::class],
],
'lifecycle' => ['pending_ttl_hours' => 24, 'reaper_frequency' => 'hourly'],
'listing' => ['per_page' => 50], // GET /documents page size
'authorization' => ['resolver' => null], // bind AuthorizesDocumentAccess
'dedup' => ['scope_resolver' => null],   // bind ResolvesDedupScope
'security' => [
    'scanner' => null,                          // bind ScansUploadedFile
    'allowed_documentable_types' => null,        // see "Security" section below
    'scan_error_policy' => 'reject',             // scanner returned Error: reject | allow
],
'storage_path' => ['generator' => null], // bind GeneratesStoragePath
'disks' => [/* per-disk server_side_encryption / kms_key_id */],
'throttle' => 'documents', // named rate limiter for the shipped routes
'audit' => ['enabled' => false, 'access_log' => false],
```

## Security

Do two things before you go to production. The out-of-the-box defaults are permissive so the package
works immediately, not because they are safe to ship as they are:

1. **The `documentable_type` allowlist.** `documentable_type` on every upload, finalize, and complete
   request is resolved through `Relation::getMorphedModel()`. If your app has not called
   `Relation::enforceMorphMap()` (or `Relation::morphMap()`), the package rejects an unmapped type by
   default. Set `config('documentable.security.allowed_documentable_types')` to an explicit array of
   allowed FQCNs only if you must accept unmapped types, and prefer a morph map where you can.

   Once a morph map is registered, send the alias, not the FQCN, as `documentable_type`:

   ```php
   // AppServiceProvider::boot()
   Relation::enforceMorphMap([
       'user' => \App\Models\User::class,
   ]);
   ```

   ```json
   // POST /documents
   {
       "documentable_type": "user",
       "documentable_id": "…",
       "document_type_id": "…"
   }
   ```

   Sending `"App\\Models\\User"` here is rejected once `enforceMorphMap()` is active, unless it is also
   in `allowed_documentable_types`.
2. **`AuthorizesDocumentAccess`.** The default is `PermissiveDocumentAuthorizer` (allows everything).
   Bind a real implementation through `config('documentable.authorization.resolver')` before production
   use; `php artisan documents:make-authorizer` scaffolds a starting point. `canUpload()` receives a
   `null` `$documentable` from `storeDetached()` and multipart `initiate()`, because no owner is attached
   yet. The generated stub branches on this explicitly instead of falling through to an ownership check
   that can never pass for `null`.

In production, booting with the routes mounted and the permissive authorizer logs one warning and shows
`Insecure in production: yes` under `php artisan about`. It does not stop the app.

You need both steps. A morph-mapped or allowlisted type with a permissive authorizer still lets any caller
attach, view, and delete documents against any instance of that type.

### Scanner errors

A scanner that returns `ScanResult::Error` (it could not reach a verdict, for example because the ClamAV
sidecar is down) no longer counts as clean. The upload is rejected and the object removed. If availability
matters more than scanning, set `documentable.security.scan_error_policy` to `allow`: the file is stored
and a warning is logged.

### Upload sessions and the bucket

Every upload runs on a server-side upload session. The client holds only the session id. The server
generates the storage path and never sends out the provider upload id, so
`POST /documents/presigned/finalize` takes a `session_id`, never a path, and cannot be pointed at another
key in your bucket. Only the user a session was started under can drive it. Finalize checks the declared
size, the content type, and the hash before the session becomes `uploaded`. An object that fails these
checks is deleted and its session aborted, and `documents:clean-orphaned` expires sessions that were never
finished.

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
