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

## HTTP routes

Package ships `routes/api.php`, auto-loaded under `/documents` when
`config('documentable.load_routes')` is true (default). Rate-limited behind the named limiter in
`config('documentable.throttle')` (default `'documents'`) — define your own rate with
`RateLimiter::for('documents', ...)` in your `AppServiceProvider`; the package registers a
permissive `Limit::none()` fallback only if you haven't. Set `load_routes` to `false` and mount
`MadeByClowd\Documentable\Http\Controllers\*` yourself for full control over prefix/middleware/guard.

## Authorization

Bind `MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess` (`config('documentable.authorization.resolver')`)
to control who can upload/view/delete — default is permissive (allows everything), replace before
production use. The shipped HTTP controllers consult it; `DocumentService` itself does not (it has
no request/user context of its own) — a direct service caller (job, command) must consult the
authorizer itself if it needs to.

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

- `php artisan documents:install` — interactive wizard: publishes config/migrations/Boost skill,
  prompts for `etag_strategy` and type-catalog mode.
- `php artisan documents:sync-types [--prune]` — see above.
- `php artisan documents:list` — table of registered types with usage counts.
- `php artisan documents:verify [--repair]` — drift-detector for the `latest_marker`/`is_latest`
  invariant; should never find anything if `DocumentService` is the only writer.
- `php artisan documents:clean-orphaned [--hours=N]` — the reaper (auto-scheduled).
- `php artisan documents:configure-bucket-lifecycle {disk} [--days=3]` — optional bucket-native
  `AbortIncompleteMultipartUpload` backstop, defense-in-depth alongside the reaper.
