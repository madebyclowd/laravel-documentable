# Laravel Documentable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![Total Downloads](https://img.shields.io/packagist/dt/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Customizable, S3-compatible-first document storage for Laravel — content-addressable dedup,
composable versioning, multipart uploads, and orphan cleanup, without forcing you to adopt one
opinionated storage backend or admin UI.

**In short:** it's the file-upload/storage system your app needs, already built — so you don't have
to reinvent versioning, deduplication, or secure uploads yourself.

## New here? Start with this section

If you've never used a package like this before, read this section first — it explains what the
package actually does, walks through your very first upload step by step, and defines every piece
of jargon you'll see later in this README. Already comfortable with S3/multipart uploads? Skip
straight to [Features](#features).

### What does this package actually do?

Does your app need to let users upload files — invoices, photos, contracts, avatars — and reliably
store, retrieve, and manage them afterward? That's what this package handles, so you don't have to
write that plumbing yourself:

- You add **one line** to any Eloquent model (`Invoice`, `User`, `Project`, ...) and it can now have
  documents attached to it.
- You define **"types"** of upload (e.g. `"invoice"`, `"profile-photo"`) — each type is just a set of
  rules: max file size, which file formats are allowed, whether old versions are kept.
- The package stores the actual files in an S3-compatible bucket (AWS S3, Cloudflare R2, MinIO,
  DigitalOcean Spaces) — **not** on your app server's own disk, which doesn't scale past one server.
- It automatically avoids storing the exact same file twice, and can optionally keep a full version
  history every time a file is replaced.

None of this requires you to already know how S3 or multipart uploads work — the quick start below
walks through everything with copy-paste commands, and the [Glossary](#glossary) explains every term
before you hit it.

### 5-minute quick start

Follow these five steps in order. By the end you'll have uploaded your first file. Every step is a
command or a small code snippet you can copy-paste as-is.

**1. Install the package:**

```bash
composer require madebyclowd/laravel-documentable
```

**2. Run the installer.** It asks a few plain-language questions and sets everything up for you —
config file, database tables, and (optionally) a starter security file:

```bash
php artisan documents:install
```

Not sure how to answer a question it asks? The **first option shown is a safe default** for most
apps — just press Enter to accept it.

**3. Let one of your models hold documents.** Pick any model you already have — this example uses
`Invoice`, but any model works the same way:

```bash
php artisan documents:attach-model Invoice
```

This adds one line (`use Documentable;`) to `app/Models/Invoice.php` for you — no manual editing
needed. Prefer to do it yourself? It's just this:

```php
use MadeByClowd\Documentable\Traits\Documentable;

class Invoice extends Model
{
    use Documentable;
}
```

**4. Tell the package what kind of file you'll be uploading.** Open `config/documentable.php`, find
the `'types'` array near the top, and add an entry:

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

Then save that type into the database (safe to run again any time you change this array):

```bash
php artisan documents:sync-types
```

**5. Upload your first file**, from any controller action:

```php
$service = app(\MadeByClowd\Documentable\Services\DocumentService::class);
$type = \MadeByClowd\Documentable\Models\DocumentType::where('code', 'invoice-pdf')->firstOrFail();

$document = $service->upload($request->file('file'), $type, $invoice);
```

Done — `$document` is saved, `$invoice->documents` lists it, and you can get a temporary link to
view/download it whenever you need one:

```php
$service->getUrl($document, now()->addMinutes(5));
```

**What's next?** The five steps above happen entirely in PHP code you write (a controller). If your
frontend (React, Vue, a mobile app) should talk to this package directly over HTTP instead —
including for large files, without routing bytes through your Laravel server — see
["Uploading via the shipped HTTP API"](#uploading-via-the-shipped-http-api) further down.

### Glossary

Terms used throughout the rest of this README, explained in plain language before you hit them:

| Term | Plain-English meaning |
|---|---|
| **Disk** | Laravel's name for "where files are stored" (`config/filesystems.php`). This package needs an S3-compatible disk — real AWS S3, or a compatible alternative like Cloudflare R2, MinIO, or DigitalOcean Spaces. |
| **DocumentType** | A named "kind" of upload (e.g. "invoice", "profile-photo") with its own rules — max size, allowed formats, whether old versions are kept. |
| **Documentable** | "The model a document belongs to" — e.g. an `Invoice`, a `User`, a `Project`. Any Eloquent model becomes documentable by adding the `Documentable` trait to it. |
| **Dedup (deduplication)** | If the exact same file is uploaded twice, the package stores the bytes once and links both uploads to that same underlying file — no wasted storage. |
| **Versioning** | Keeping the history of past uploads for the same "slot" instead of throwing the old file away the moment a new one replaces it. |
| **Presigned URL / direct-PUT upload** | A short-lived, temporary link that lets the browser send file bytes straight to your S3 bucket — the file never passes through your Laravel app server. Used for smaller files. |
| **Multipart upload** | The technique used for large files: the file is split into pieces ("parts"), each uploaded separately (often in parallel), then assembled on the bucket. Handles slow or flaky connections far better than one giant upload. |
| **ETag** | A checksum the storage provider returns for each uploaded part, used to confirm a multipart upload assembled correctly. |
| **Morph type / `documentable_type`** | Laravel's internal name for "which kind of model" a document is attached to (e.g. `App\Models\Invoice`) — you'll see this field in the HTTP request examples below. |
| **Authorizer** | The piece of code that decides who's allowed to upload, view, or delete a given document. The package ships a permissive default (allows everyone) plus a command to generate a real one — see [Security](#security). |

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
through the app-shape, `etag_strategy`, and DocumentType-catalog choices (writing the answers into
your published config instead of leaving unconsidered defaults in place).

Scripted/CI install — pass `--shape`/`--etag-strategy`/`--types` to skip the interactive prompts:

```bash
php artisan documents:install --no-interaction \
    --shape=monolith \
    --etag-strategy=server-authoritative \
    --types=code-first
```

Running `--no-interaction` **without** `--shape` prints a loud warning and falls back to
`separate-api` (`middleware => ['api']`, no session/auth — `$request->user()` stays `null`) rather
than silently keeping that default with no indication it happened. `--etag-strategy`/`--types` have
no comparable security consequence, so they default silently under `--no-interaction` when omitted.

Manual, fully non-interactive equivalent (no installer at all):

```bash
php artisan vendor:publish --tag=documentable-config
php artisan vendor:publish --tag=documentable-migrations
php artisan migrate
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
your own token guard in). If `$request->user()` is unexpectedly `null` in your authorizer or
`created_by`/`deleted_by`, this is why — see the Troubleshooting section of this package's Boost
skill, or re-run `documents:install` and pick "monolith".

### Listing document types

```
GET /documents/types
```

Read-only catalog of active `DocumentType`s — `id`/`code`/`name`/`max_size_mb`/`allowed_mimes`/
`allows_multiple`/`requires_versioning`, no pagination. Gives the frontend a way to fetch a
`document_type_id` without direct DB access or `php artisan documents:list` (CLI-only). Soft-deleted
(deactivated) types are excluded.

### Listing an owner's documents

```
GET /documents?documentable_type=...&documentable_id=...[&document_type_id=...][&page=1]
```

Returns every latest-per-slot document for that owner, grouped `{document_type_id: {document_group_id: document}}`
(`storageFile` eager-loaded, same shape as the upload endpoints). `canView()` is applied per document
after the query — a custom authorizer denying some documents excludes just those, not the whole set —
so pagination (`config('documentable.listing.per_page')`, default 50) runs over the filtered result:

```json
{
  "data": { "<document_type_id>": { "<document_group_id>": { "id": "...", "storage_file": {...} } } },
  "meta": { "current_page": 1, "per_page": 50, "total": 3, "last_page": 1 }
}
```

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

**Resuming after a dropped connection.** Before re-uploading anything, check what actually landed:

```
GET /documents/multipart/status?path=...&upload_id=...&user_id=...
→ { "exists": true, "expires_at": "2026-01-01T00:00:00+00:00", "disk": "s3" }
```

`exists: false` (still a `200`, not an error) means the session is gone — reaped, aborted, or never
existed — start a new `initiate` instead of retrying against it. If it exists, diff your local
part-tracking against the bucket's own record before resuming:

```
GET /documents/multipart/parts?path=...&upload_id=...&document_type_id=...&user_id=...
→ { "parts": [{"PartNumber": 1, "ETag": "\"...\""}] }
```

Re-upload only the part numbers missing from this list — re-uploading a part that's already there
is always safe (last-write-wins across S3/R2/MinIO/Spaces — see the `MultipartUploadDriver`
interface docblock for the exact provider-by-provider guarantee, including one R2 edge case), but
diffing first avoids the wasted transfer.

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
  whole point of the direct-PUT path — never force multipart on small files.
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
- **Only capture the `ETag` response header under `etag_strategy = client`** — see step 2 of the
  multipart walkthrough above for exactly when it matters.
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

**Bucket CORS**: both the direct-PUT and multipart part-upload flows have the browser `PUT` bytes
straight to the bucket, cross-origin from your app — this requires the bucket to allow `PUT` (and,
under `etag_strategy = client`, `ExposeHeaders: ["ETag"]`) from your app's origin.
`php artisan documents:configure-bucket-cors {disk} --origin=https://your-app.example.com` applies
this for you; `--verify` runs a live presigned-PUT smoke test afterward.

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
| `documents:attach-model {model}` | Add `use Documentable;` (and its import) to an existing model. |
| `documents:sync-types [--prune]` | Upsert `config('documentable.types')` into `document_types`. |
| `documents:list` | Table of registered types with usage counts. |
| `documents:verify [--repair]` | Detect (and optionally fix) `latest_marker`/`is_latest` drift. |
| `documents:clean-orphaned [--hours=N]` | Reaper — purges expired pending documents, aborts stale multipart sessions. Auto-scheduled. |
| `documents:configure-bucket-lifecycle {disk} [--days=3]` | Optional bucket-native `AbortIncompleteMultipartUpload` backstop. |
| `documents:configure-bucket-cors {disk} --origin=... [--verify]` | Apply/verify the bucket CORS policy direct-PUT and multipart part uploads need. |

## AI agent context (Laravel Boost)

The package ships `resources/boost/guidelines/core.blade.php` (upfront package overview) and
`resources/boost/skills/laravel-documentable/SKILL.md` (detailed reference — upload transports,
versioning semantics, security checklist, every artisan command). If you have
[Laravel Boost](https://github.com/laravel/boost) installed (`composer require laravel/boost --dev`
+ `php artisan boost:install`), both are **auto-discovered from `vendor/`** — nothing to run in
this package. Boost decides the actual per-agent install location itself (its `ClaudeCode` agent
class targets `.claude/skills/`, other supported agents have their own conventions) — this package
doesn't hardcode a directory for that, and no longer tries to guess one.

Without Boost, read the files directly from
`vendor/madebyclowd/laravel-documentable/resources/boost/` and copy the skill into whatever your
AI tool expects (Claude Code: `.claude/skills/laravel-documentable/SKILL.md` project-level, or
`~/.claude/skills/` for personal use across projects).

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
'listing' => ['per_page' => 50], // GET /documents page size
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

Two things you should do before going to production — the out-of-the-box defaults are intentionally
permissive so the package works immediately, not because they're safe to ship as-is:

1. **`documentable_type` allowlist.** `documentable_type` on every upload/finalize/complete request
   is resolved via `Relation::getMorphedModel()`. If your app hasn't called
   `Relation::enforceMorphMap()` (or `Relation::morphMap()`), an unmapped type is **rejected** by
   default — set `config('documentable.security.allowed_documentable_types')` to an explicit array
   of allowed FQCNs only if you need to accept unmapped types, and prefer a morph map instead where
   possible.

   Once a morph map is registered, send the **alias**, not the FQCN, as `documentable_type`:

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

   Sending `"App\\Models\\User"` here is rejected once `enforceMorphMap()` is active, unless it's
   also present in `allowed_documentable_types`.
2. **`AuthorizesDocumentAccess`.** The default is `PermissiveDocumentAuthorizer` (allows everything).
   Bind a real implementation via `config('documentable.authorization.resolver')` before production
   use — `php artisan documents:make-authorizer` scaffolds a starting point. Note `canUpload()`
   receives a `null` `$documentable` from `storeDetached()`/multipart `initiate()` (no owner attached
   yet) — the generated stub branches on this explicitly rather than falling through to an ownership
   check that can never pass for `null`.

Neither of these is optional in combination: an allowlisted/morph-mapped type with a permissive
authorizer still lets any caller attach/view/delete documents against any instance of that type.

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
