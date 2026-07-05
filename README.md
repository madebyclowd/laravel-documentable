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
'security' => ['scanner' => null],       // bind ScansUploadedFile
'storage_path' => ['generator' => null], // bind GeneratesStoragePath
'disks' => [/* per-disk server_side_encryption / kms_key_id */],
'throttle' => 'documents', // named rate limiter for the shipped routes
'audit' => ['enabled' => false, 'access_log' => false],
```

## Development status

Built in phases — see `docs/implementations/` for the full history and rationale:

- [x] Phase 0 — package scaffold
- [x] Phase 1 — core schema & direct upload
- [x] Phase 2 — versioning & multi-document groups
- [x] Phase 3 — multipart upload
- [x] Phase 4 — lifecycle & orphan cleanup
- [x] Phase 5 — pluggable contracts & security
- [x] Phase 6 — events & observability
- [x] Phase 7 — routes, install command, docs, release

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
