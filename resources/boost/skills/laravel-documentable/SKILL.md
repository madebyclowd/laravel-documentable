---
name: laravel-documentable
description: Build and work with laravel-documentable — S3-compatible document storage, versioning, multipart/presigned uploads, and authorization for madebyclowd/laravel-documentable.
---

# Laravel Documentable

Customizable, S3-compatible-first document storage for Laravel: content-addressable dedup,
composable versioning, multipart uploads, orphan cleanup, and pluggable authorization/security/
storage-path contracts. This skill explains how to wire it into a consuming app, in depth — read
the section you need, don't assume prior familiarity with S3/multipart internals.

## Quick orientation (read this first)

Three pieces work together:

1. **`DocumentType`** — a named "kind" of upload (e.g. `invoice`, `avatar`), a DB row holding rules:
   max size, allowed mimes, which disk, whether multiple slots are allowed, whether history is kept.
2. **`Document`** — one uploaded file, always attached to some model via `documentable_type`/
   `documentable_id` (or temporarily unattached — see "Detached uploads" below).
3. **`StorageFile`** — the actual bytes on a disk, content-addressed by sha256. Multiple `Document`
   rows can point at the same `StorageFile` (dedup) — deleting a `Document` never deletes the
   physical object until every `Document` referencing it is gone (`purge()`, reference-counted).

Every write path (owned upload, detached upload, direct-PUT finalize, multipart complete) funnels
through `MadeByClowd\Documentable\Services\DocumentService`. Never insert into `documents` or
`storage_files` directly — dedup, versioning, and lifecycle bookkeeping all live in that service.

## Attaching to a model

```php
use MadeByClowd\Documentable\Traits\Documentable;

class Invoice extends Model
{
    use Documentable;
}

$invoice->documents(); // MorphMany<Document>
```

Or skip the manual edit: `php artisan documents:attach-model Invoice` adds the import and
`use Documentable;` for you — refuses (prints why, touches nothing) rather than guessing if the
file's `use` statements aren't in a simple, unambiguous shape (e.g. a comma-joined trait-use list or
a `{...}` conflict-resolution block — those need a human, not a regex).

## Defining a DocumentType

Types are code/config-first (recommended) or fully DB-managed — pick **one mode per app**, not per
type; mixing them isn't supported.

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

Run `php artisan documents:sync-types` (idempotent, safe alongside `migrate` in a deploy pipeline)
to upsert config-defined types into the `document_types` table by `code`. Add `--prune` to
soft-deactivate DB types missing from config — never hard-deleted, so FK integrity for existing
`Document` rows referencing a retired type is preserved. `php artisan documents:list` shows every
registered type (active or not) with live usage counts — the fastest way to sanity-check what's
actually in the database versus what's in config.

Fully dynamic mode: leave `config('documentable.types')` empty and manage `document_types` directly
through your own admin layer instead. Same table either way — the package ships the data layer only,
building admin CRUD is explicitly not package scope.

## `allows_multiple` × `requires_versioning`

These two flags compose independently — read them as two separate questions, not one combined mode:

- **`allows_multiple`**: how many independent "slots" (`document_group_id`) can this owner+type have?
  `false` = exactly one slot ever (e.g. "current signed contract" — there's only one). `true` = as
  many as you create (e.g. "attachments" — pass `$documentGroupId` to `DocumentService::upload()` to
  add a new version *to a specific existing slot*; omit it to start a brand new slot).
- **`requires_versioning`**: within one slot, does a new upload keep history (soft-deletes the old
  row and increments `version`) or hard-replace in place (old row soft-deleted immediately, no
  history kept, next version is always `1`)?

All four combinations are valid and meaningful:

| `allows_multiple` | `requires_versioning` | What you get |
|---|---|---|
| `false` | `false` | One slot, no history — every upload just replaces the last (e.g. "profile avatar"). |
| `false` | `true` | One slot, full history (e.g. "current contract", every past version kept). |
| `true` | `false` | Many independent slots, none of them versioned (e.g. "receipts" — each upload is its own, unrelated receipt). |
| `true` | `true` | Many independently-versioned slots (e.g. "attachments", each with its own edit history). |

## Uploading

```php
$service = app(\MadeByClowd\Documentable\Services\DocumentService::class);

// Owned upload — small/direct path is picked automatically under
// config('documentable.multipart.threshold_bytes'); for files at/above the
// threshold, use the multipart session methods
// (initiateMultipartUpload/generatePartUploadUrl/completeMultipartUpload) or
// the presigned direct-PUT pair (createPresignedUpload/finalizeDirectUpload)
// instead of streaming the file through your app server.
$document = $service->upload($request->file('file'), $type, $invoice);
```

### Detached uploads (no owner yet)

Sometimes the file exists before the record it belongs to does (e.g. a multi-step wizard where the
user uploads a document before submitting the rest of the form):

```php
$document = $service->uploadDetached($request->file('file'), $type, pending: true, ttlHours: 24);
// ...later, once the real owner record exists:
$service->reassociateDocument($document, $invoice);
$document->commit(); // clears expires_at, marks permanent
```

Pending, uncommitted `uploadDetached()` documents and abandoned multipart sessions are swept by the
`documents:clean-orphaned` command (scheduled automatically per
`config('documentable.lifecycle.reaper_frequency')`) — past `expires_at`, a pending `Document` is
purged (dedup-safe — the underlying `StorageFile` is only deleted if nothing else references it) and
a stale `MultipartUpload` session is aborted on the provider itself, not just deleted from the DB.

## HTTP upload flows (via the shipped `/documents` routes)

Client (browser/mobile) talks to the bucket directly for file bytes — the app server only ever
handles small JSON requests, never the file body. Both flows below have a service-call-only
equivalent (no HTTP) further down. Every route lives under `config('documentable.middleware')`
(default `['api']`) plus a throttle — see "HTTP routes" below for the full middleware story.

**Small files — presigned direct PUT** (`multipart.threshold_bytes`, default 10MB):

1. `POST /documents/presigned {document_type_id, filename}` → `{url, headers, path, disk}`.
2. Client `PUT`s the raw file to `url` (no auth — this request never touches your app server).
3. `POST /documents/presigned/finalize {path, document_type_id, documentable_type, documentable_id,
   filename, expected_hash}` → `Document` JSON (with `storage_file` eager-loaded), 201. Server
   re-downloads, re-hashes, compares to `expected_hash`; on mismatch deletes the object and returns a
   validation error (no orphaned blob left behind either way).

**Large files — multipart** (`multipart.threshold_bytes` and above):

1. `POST /documents/multipart/initiate {filename, document_type_id, user_id}` →
   `{upload_id, path, disk}`. Creates the bucket session + a DB row scoped to `user_id` — every
   later call for this session must supply the *same* `user_id` or is rejected (real ownership
   check, not decorative). Omit `user_id` to fall back to `$request->user()->getAuthIdentifier()`.
2. For each part (1-indexed, ≥5MB except the last): `POST /documents/multipart/part-url {path,
   upload_id, part_number, document_type_id, user_id}` → `{url}`; client `PUT`s that part's bytes to
   `url`. Only capture the response `ETag` header if `etag_strategy = client` — under the default
   `server-authoritative` it's unused, the server re-derives everything from `ListParts` at
   completion.
3. `POST /documents/multipart/complete {path, upload_id, user_id, document_type_id,
   documentable_type, documentable_id, filename, expected_hash, parts?}` → `Document` JSON, 201.
   `parts` (`[{PartNumber, ETag}]`) is only read under `etag_strategy = client`; omit under
   `server-authoritative`.
4. If the client gives up partway: `POST /documents/multipart/abort {path, upload_id,
   document_type_id, user_id}`. If nobody calls this, `documents:clean-orphaned` sweeps the session
   after `multipart.session_ttl_hours` and aborts it on the bucket too.

### Resuming a dropped multipart upload

Two read-only endpoints exist specifically for building correct resume logic on the client — don't
re-upload everything from scratch after a dropped connection, and don't trust client-side
bookkeeping alone (it can be stale, lost, or lying about what actually reached the bucket):

- `GET /documents/multipart/status?path=...&upload_id=...&user_id=...` →
  `{exists, expires_at, disk}`. `exists: false` is a normal `200`, not an error — it means the
  session is gone (reaped, aborted, or never existed): start a fresh `initiate` instead of retrying
  against it.
- `GET /documents/multipart/parts?path=...&upload_id=...&document_type_id=...&user_id=...` →
  `{parts: [{PartNumber, ETag}]}` — the parts the bucket *actually* has, straight from the provider's
  own `ListParts`. Diff this against what the client thinks it uploaded, and only re-upload what's
  missing.

Re-uploading a part number that's already there is always safe regardless — see "Multipart part
retry safety" below — this pair just avoids the wasted transfer of re-sending parts that already
landed.

Service-call equivalent of both flows, no HTTP:

```php
// Direct PUT:
$presigned = $service->createPresignedUpload($type, $filename);
$document = $service->finalizeDirectUpload($presigned['path'], $type, $invoice, $filename, $expectedHash);

// Multipart:
$session = $service->initiateMultipartUpload($filename, $type, $userId);
$url = $service->generatePartUploadUrl($session['path'], $session['upload_id'], $userId, $partNumber, $type);
$document = $service->completeMultipartUpload(
    $session['path'], $session['upload_id'], $userId, $type, $invoice, $filename,
    clientParts: null, expectedHash: $hash
);

// Resume helpers, same as the HTTP endpoints above:
$status = $service->multipartSessionStatus($session['path'], $session['upload_id'], $userId);
$parts = $service->listPartsForSession($session['path'], $session['upload_id'], $userId, $type);
```

## Frontend integration — what the client must handle itself

The package doesn't run in the browser, so these are frontend responsibilities, not gaps to route
around server-side:

- **Transport choice is the frontend's job.** Nothing auto-routes small vs large files for the HTTP
  API — compare `file.size` to `multipart.threshold_bytes` client-side and call `/documents/presigned`
  or `/documents/multipart/initiate` accordingly. A frontend that always uses multipart regardless of
  size defeats the direct-PUT path entirely.
- **No dedup pre-check/handshake endpoint ships with the package.** If a consuming app wants "skip
  the upload if this exact file is already stored" before transferring bytes, that's a custom
  endpoint the host app adds itself. Content dedup still happens automatically once bytes land
  server-side (same hash → reused `StorageFile`) — only the network-transfer skip requires custom
  work.
- **Responses are unwrapped JSON**, no `{status, data}` envelope — `response.data` directly.
- **`etag_strategy = server-authoritative` (default): report `{PartNumber}` only per part**, don't
  read the `ETag` response header. Only capture/report `ETag` under `etag_strategy = client`.
- **Chunk size ≥5MB** (S3 minimum, except the last part); call `/documents/multipart/abort`
  proactively on cancel/unmount instead of relying solely on the scheduled reaper; retry a failed
  part PUT a few times before failing the whole file.
- **`documentable_type`/`documentable_id` should be the real owning record**, not reflexively the
  authenticated user — the package's morph resolution is generic on purpose. If a morph map is
  registered (`Relation::enforceMorphMap()`), send the map **alias** (e.g. `"invoice"`), not the raw
  class string — see "Security" below.

## Multipart `etag_strategy`

Two legitimate modes, not one "correct" one — pick based on whether you control your bucket's CORS
config:

- `server-authoritative` (default): client reports part numbers only, server always resolves ETags
  via `ListParts` before completing. No CORS dependency — works on SaaS-managed/limited-CORS
  buckets.
- `client`: client captures `ETag` from each part's presigned-PUT response and reports it back.
  Fewer round trips, but requires bucket CORS `ExposeHeaders: ["ETag"]` — apply this with
  `php artisan documents:configure-bucket-cors {disk} --origin=https://your-app.example.com`
  (`--verify` runs a live presigned-PUT smoke test afterward).

Either way, integrity is verified independently via a server-computed sha256 compared against the
client's declared hash — an ETag is never a real content checksum on any provider.

### Multipart part retry safety

Re-uploading the same `PartNumber` any number of times *before* `complete()`/`abort()` is called is
always safe and last-write-wins across S3, R2, MinIO, and Spaces — a client that can't tell whether
an ambiguous PUT failure actually landed may always just retry that part number, no need to call
`listParts()` first to check. One nuance: this is directly documented by AWS S3 and Cloudflare R2;
MinIO and DigitalOcean Spaces commit to it only by explicit reference to the AWS spec rather than
restating it themselves. Cloudflare R2 also flags one edge case — if the *retry itself* fails
mid-flight, that part number can be left with no stored data rather than falling back to the prior
copy, so confirm the retry actually succeeded rather than assuming "I retried, so it's fine now."
Full citations live in the `MultipartUploadDriver` interface docblock in source.

## Listing document types

```
GET /documents/types
```

Read-only, active-only (`whereNull('deleted_at')`) catalog: `id`, `code`, `name`, `max_size_mb`,
`allowed_mimes`, `allows_multiple`, `requires_versioning`. No pagination (type catalogs are small,
config/DB-managed, not a growing per-user collection). This is how a frontend gets a
`document_type_id` to build an upload request without direct DB access or the `documents:list` CLI
command (which is operator-facing table output, not JSON).

## Listing an owner's documents

```
GET /documents?documentable_type=...&documentable_id=...[&document_type_id=...][&page=1]
```

Returns every latest-per-slot document for that owner, grouped `{document_type_id: {document_group_id: document}}`
(`storageFile` eager-loaded). `canView()` is applied per document after the query — a custom
authorizer denying some documents excludes just those, not the whole set — so
`config('documentable.listing.per_page')` (default 50) paginates the already-filtered result, not
a raw DB query. Service-call equivalent: `DocumentService::listForOwner($documentable, $documentTypeId = null)`
(returns the flat `Collection<Document>`, no grouping/pagination/authorization — that's the
controller's job, same seam as everywhere else in this package).

## HTTP routes

Package ships `routes/api.php`, auto-loaded under `/documents` when
`config('documentable.load_routes')` is true (default). Middleware stack is
`config('documentable.middleware')` (default `['api']` — **no session/auth**, `$request->user()`
is `null`) plus a throttle behind the named limiter in `config('documentable.throttle')` (default
`'documents'`) — define your own rate with `RateLimiter::for('documents', ...)` in your
`AppServiceProvider`; the package registers a permissive `Limit::none()` fallback only if you
haven't. Set `load_routes` to `false` and mount `MadeByClowd\Documentable\Http\Controllers\*`
yourself for full control over prefix/middleware/guard.

`php artisan documents:install` asks whether the app is a session-based monolith (sets
`['web', 'auth']`) or a separate API (stays `['api']`, wire your own token guard in — e.g.
`auth:sanctum`). Scriptable/CI installs can skip the prompts entirely:

```bash
php artisan documents:install --no-interaction \
    --shape=monolith \
    --etag-strategy=server-authoritative \
    --types=code-first
```

Running `--no-interaction` **without** `--shape` prints a loud warning and falls back to
`separate-api` rather than silently keeping that default with no indication it happened — this is
the one install choice with a real security consequence (a wrong choice means every route runs with
`$request->user()` always `null`). `--etag-strategy`/`--types` have no comparable consequence and
default silently when omitted non-interactively.

## Security

Two things to do before production use — the out-of-the-box state is intentionally permissive so
the package works immediately, not because either default is safe to ship:

1. **`documentable_type` allowlist.** Every `documentable_type` on an upload/finalize/complete/list
   request is resolved via `Relation::getMorphedModel()`. An unmapped type is **rejected** unless
   it's in `config('documentable.security.allowed_documentable_types')` (default `null` = reject
   all unmapped types) — call `Relation::enforceMorphMap()` in your app instead where possible;
   only reach for the allowlist if you can't. Once a morph map is registered, send the **alias**
   (e.g. `"invoice"`), not the FQCN (`"App\\Models\\Invoice"`), as `documentable_type` — sending the
   FQCN is rejected once the map is active unless it's also in the allowlist.
2. **`AuthorizesDocumentAccess`.** Default is `PermissiveDocumentAuthorizer` (allows everything).
   Bind a real implementation via `config('documentable.authorization.resolver')` — the shipped
   HTTP controllers consult it (`canUpload`/`canView`/`canDelete`); `DocumentService` itself does
   not (no request/user context of its own) — a direct service caller (job, command) must consult
   the authorizer itself if it needs to. `php artisan documents:make-authorizer` scaffolds a
   starting implementation in `app/Documentable` instead of a blank-page interface. **Watch the
   `null` `$documentable` case**: `canUpload()` is called with `$documentable = null` from
   `storeDetached()` and multipart `initiate()` (no owner attached yet) — the generated stub
   branches on this explicitly and denies by default; if you write your own implementation from the
   interface directly, handle this case too, or every detached/initiate call silently 403s with no
   obvious cause.

Neither is optional in combination: an allowlisted/morph-mapped type with a permissive authorizer
still lets any caller act on any instance of that type.

## Other pluggable contracts

- `ScansUploadedFile` (`config('documentable.security.scanner')`) — AV/malware scan hook, runs on
  every newly-stored (non-dedup-hit) file. Default is a no-op that reports clean.
- `ResolvesDedupScope` (`config('documentable.dedup.scope_resolver')`) — default dedups globally by
  content hash; override to scope by tenant if cross-tenant hash collisions are a concern.
- `GeneratesStoragePath` (`config('documentable.storage_path.generator')`) — default
  `"{$type->path_prefix}/{uuid}"`; override for date/tenant-sharded layouts.

## Events

`DocumentUploaded`, `DocumentVersionSuperseded`, `DocumentDeleted`, `DocumentPurged`,
`DocumentReassociated`, `MultipartUploadInitiated`, `MultipartUploadAborted` — listen instead of
subclassing `DocumentService` for side effects (thumbnailing, external audit sinks, notifications).

## Audit / observability

`config('documentable.audit.enabled')` — populates `created_by`/`deleted_by` on `Document` from the
authenticated actor (best-effort, null in unauthenticated/CLI/queued contexts). `config('documentable.audit.access_log')`
— writes a `document_access_logs` row every time `getUrl()` is called (the closest available proxy
for "access", since the package can't observe requests to a presigned URL directly). Both off by
default to stay lean.

## Operational commands

- `php artisan documents:install [--shape=] [--etag-strategy=] [--types=]` — interactive wizard:
  publishes config/migrations, prompts for app shape (monolith vs. separate API — sets
  `documentable.middleware`), `etag_strategy`, type-catalog mode, and optionally scaffolds an
  authorizer. Pass the options to skip prompts (see "HTTP routes" above).
- `php artisan documents:sync-types [--prune]` — see "Defining a DocumentType" above.
- `php artisan documents:list` — table of registered types with usage counts.
- `php artisan documents:verify [--repair]` — drift-detector for the `latest_marker`/`is_latest`
  invariant; should never find anything if `DocumentService` is the only writer.
- `php artisan documents:clean-orphaned [--hours=N]` — the reaper (auto-scheduled).
- `php artisan documents:configure-bucket-lifecycle {disk} [--days=3]` — optional bucket-native
  `AbortIncompleteMultipartUpload` backstop, defense-in-depth alongside the reaper.
- `php artisan documents:configure-bucket-cors {disk} --origin=... [--verify]` — applies the bucket
  CORS policy the direct-PUT and multipart part-upload flows need (browser `PUT`s bytes straight to
  the bucket, cross-origin from your app). Refuses to run without at least one `--origin` (no silent
  wildcard). `--verify` performs a live presigned-PUT smoke test afterward.
- `php artisan documents:make-authorizer {name=AppDocumentAuthorizer}` — scaffold a starter
  `AuthorizesDocumentAccess` implementation in `app/Documentable`.
- `php artisan documents:attach-model {model}` — add `use Documentable;` (and its import) to an
  existing model.

## Troubleshooting — common mistakes and how to spot them

- **`$request->user()` is always `null` in my authorizer / `created_by` is always `null`.** Your
  routes are mounted under the default `['api']` middleware (no session/auth). If this is a
  session-based app (Blade/Inertia/Livewire), re-run `php artisan documents:install` and pick
  "monolith" this time, or set `config('documentable.middleware', ['web', 'auth'])` yourself.
- **Every upload gets a 403, and I can't tell why.** Check whether your `AuthorizesDocumentAccess`
  implementation handles `$documentable === null` in `canUpload()` — it's called that way from
  `storeDetached()`/multipart `initiate()`. A custom implementation that falls through to an
  ownership check (which can never pass for `null`) silently denies every one of those calls.
- **`GET /documents/{id}/url` throws `RuntimeException: This driver does not support creating
  temporary URLs.`** — the document's disk doesn't support `Storage::temporaryUrl()`. This affects
  *every* document on that disk regardless of size, not just multipart uploads — `local`/`public`
  disks need a registered `Storage::disk($disk)->buildTemporaryUrlsUsing(...)` callback, or switch
  to an S3-compatible disk. (As of this package's exception wrapping, the error you'll actually see
  is `DiskDoesNotSupportTemporaryUrlsException` with this exact guidance in the message.)
- **Browser upload fails with a CORS error, only in the browser (curl/Postman work fine).** Your
  bucket doesn't allow cross-origin `PUT` from your app's origin yet. Run
  `php artisan documents:configure-bucket-cors {disk} --origin=https://your-app.example.com`.
- **`documentable_type` gets rejected even though the model class definitely exists.** Either
  register a morph map (`Relation::enforceMorphMap()`) and send the **alias**, not the FQCN — or add
  the FQCN to `config('documentable.security.allowed_documentable_types')` if you can't use a morph
  map. An unmapped, non-allowlisted type is rejected by design (see "Security" above).
- **Any consumer can upload/view/delete any document right after install.** Expected out-of-the-box
  behavior — the default authorizer is permissive on purpose so the package works immediately in
  development. Run `php artisan documents:make-authorizer` and bind a real implementation before
  production.
- **`documents:install --no-interaction` in CI silently used the wrong app shape.** Pass `--shape=`
  explicitly in scripted installs — omitting it under `--no-interaction` prints a warning and
  defaults to `separate-api`, which may not match your app.
