---
name: laravel-documentable
description: Build and work with laravel-documentable — S3-compatible document storage, versioning, multipart/presigned uploads, and authorization for madebyclowd/laravel-documentable.
---

# Laravel Documentable

Customizable, S3-compatible-first document storage for Laravel: content-addressable dedup,
composable versioning, multipart uploads, orphan cleanup, and pluggable authorization/security/
storage-path contracts. This skill explains how to wire it into a consuming app.

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
`use Documentable;` for you — refuses (prints why) rather than guessing if the file's `use`
statements aren't in a simple, unambiguous shape.

## Defining a DocumentType

Types are code/config-first (recommended) or fully DB-managed — pick one per app, not per type.

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
`Document` rows referencing a retired type is preserved.

Fully dynamic mode: leave `config('documentable.types')` empty and manage `document_types` directly
through your own admin layer instead. Same table either way, package ships the data layer only —
building admin CRUD is explicitly not package scope.

## `allows_multiple` × `requires_versioning`

These two flags compose independently — read them as two separate questions:

- `allows_multiple`: how many independent "slots" (`document_group_id`) can this owner+type have?
  `false` = exactly one slot ever. `true` = as many as you create (pass `$documentGroupId` to
  `DocumentService::upload()` to add a new version *to a specific existing slot*; omit it to start a
  brand new slot).
- `requires_versioning`: within one slot, does a new upload keep history (soft-deletes and increments
  `version`) or hard-replace in place (old row soft-deleted immediately, no history, next version is
  always 1)?

Every combination is valid: e.g. `allows_multiple = true` + `requires_versioning = true` gives N
independently-versioned document slots per owner (e.g. "attachments", each with its own edit
history).

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

// Detached upload (no owner yet) — e.g. upload before the real record exists.
$document = $service->uploadDetached($request->file('file'), $type, pending: true, ttlHours: 24);
// ...later, once the real owner exists:
$service->reassociateDocument($document, $invoice);
$document->commit(); // clears expires_at, marks permanent
```

Pending, uncommitted `uploadDetached()` documents and abandoned multipart sessions are swept by the
`documents:clean-orphaned` command (scheduled automatically per
`config('documentable.lifecycle.reaper_frequency')`) — past `expires_at`, a pending `Document` is
purged (dedup-safe) and a stale `MultipartUpload` session is aborted on the provider, not just
deleted from the DB.

## HTTP upload flows (via the shipped `/documents` routes)

Client (browser/mobile) talks to the bucket directly for file bytes — the app server only ever
handles small JSON requests, never the file body. Both flows below have a service-call-only
equivalent (no HTTP) further down.

**Small files — presigned direct PUT** (`multipart.threshold_bytes`, default 10MB):

1. `POST /documents/presigned {document_type_id, filename}` → `{url, headers, path, disk}`.
2. Client `PUT`s the raw file to `url` (no auth — this request never touches your app server).
3. `POST /documents/presigned/finalize {path, document_type_id, documentable_type, documentable_id,
   filename, expected_hash}` → `Document` JSON, 201. Server re-downloads, re-hashes, compares to
   `expected_hash`; on mismatch deletes the object and returns a validation error (no orphaned blob).

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
```

## Frontend integration — what the client must handle itself

The package doesn't run in the browser, so these are frontend responsibilities, not gaps to route
around server-side:

- **Transport choice is the frontend's job.** Nothing auto-routes small vs large files for the HTTP
  API — compare `file.size` to `multipart.threshold_bytes` client-side and call `/documents/presigned`
  or `/documents/multipart/initiate` accordingly. A frontend that always uses multipart regardless of
  size defeats the direct-PUT path entirely (best-practices.md §1).
- **No dedup pre-check/handshake endpoint ships with the package** — `package-plan.md` §2 named this
  as a concept worth keeping from the reference implementation, but it was never actually built in
  any phase. If a consuming app wants "skip the upload if this exact file is already stored" before
  transferring bytes, that's a custom endpoint the host app adds itself. Content dedup still happens
  automatically once bytes land server-side (same hash → reused `StorageFile`) — only the
  network-transfer skip requires custom work.
- **Responses are unwrapped JSON**, no `{status, data}` envelope — `response.data` directly.
- **`etag_strategy = server-authoritative` (default): report `{PartNumber}` only per part**, don't
  read the `ETag` response header. Only capture/report `ETag` under `etag_strategy = client`.
- **Chunk size ≥5MB** (S3 minimum, except the last part); call abort proactively on
  cancel/unmount instead of relying solely on the scheduled reaper; retry a failed part PUT before
  failing the whole file.
- **`documentable_type`/`documentable_id` should be the real owning record**, not reflexively the
  authenticated user — the package's morph resolution is generic on purpose.

## Multipart `etag_strategy`

Two legitimate modes, not one "correct" one — pick based on whether you control your bucket's CORS
config:

- `server-authoritative` (default): client reports part numbers only, server always resolves ETags
  via `ListParts` before completing. No CORS dependency — works on SaaS-managed/limited-CORS
  buckets.
- `client`: client captures `ETag` from each part's presigned-PUT response and reports it back.
  Fewer round trips, but requires bucket CORS `ExposeHeaders: ["ETag"]`.

Either way, integrity is verified independently via a server-computed sha256 compared against the
client's declared hash — an ETag is never a real content checksum on any provider.

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
is `null`; `php artisan documents:install` asks whether the app is a session-based monolith
(`['web', 'auth']`) or a separate API, and writes the choice back to config) plus a throttle
behind the named limiter in `config('documentable.throttle')` (default `'documents'`) — define
your own rate with `RateLimiter::for('documents', ...)` in your `AppServiceProvider`; the package
registers a permissive `Limit::none()` fallback only if you haven't. Set `load_routes` to `false`
and mount `MadeByClowd\Documentable\Http\Controllers\*` yourself for full control over
prefix/middleware/guard.

## Security

Two things to do before production use — the out-of-the-box state is intentionally permissive so
the package works immediately, not because either default is safe to ship:

1. **`documentable_type` allowlist.** Every `documentable_type` on an upload/finalize/complete/list
   request is resolved via `Relation::getMorphedModel()`. An unmapped type is **rejected** unless
   it's in `config('documentable.security.allowed_documentable_types')` (default `null` = reject
   all unmapped types) — call `Relation::enforceMorphMap()` in your app instead where possible;
   only reach for the allowlist if you can't.
2. **`AuthorizesDocumentAccess`.** Default is `PermissiveDocumentAuthorizer` (allows everything).
   Bind a real implementation via `config('documentable.authorization.resolver')` — the shipped
   HTTP controllers consult it (`canUpload`/`canView`/`canDelete`); `DocumentService` itself does
   not (no request/user context of its own) — a direct service caller (job, command) must consult
   the authorizer itself if it needs to. `php artisan documents:make-authorizer` scaffolds a
   starting implementation in `app/Documentable` instead of a blank-page interface.

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

- `php artisan documents:install` — interactive wizard: publishes config/migrations, prompts for
  app shape (monolith vs. separate API — sets `documentable.middleware`), `etag_strategy`,
  type-catalog mode, and optionally scaffolds an authorizer.
- `php artisan documents:sync-types [--prune]` — see above.
- `php artisan documents:list` — table of registered types with usage counts.
- `php artisan documents:verify [--repair]` — drift-detector for the `latest_marker`/`is_latest`
  invariant; should never find anything if `DocumentService` is the only writer.
- `php artisan documents:clean-orphaned [--hours=N]` — the reaper (auto-scheduled).
- `php artisan documents:configure-bucket-lifecycle {disk} [--days=3]` — optional bucket-native
  `AbortIncompleteMultipartUpload` backstop, defense-in-depth alongside the reaper.
- `php artisan documents:make-authorizer {name=AppDocumentAuthorizer}` — scaffold a starter
  `AuthorizesDocumentAccess` implementation in `app/Documentable`.
- `php artisan documents:attach-model {model}` — add `use Documentable;` (and its import) to an
  existing model.
