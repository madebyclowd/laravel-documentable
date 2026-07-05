<?php

use MadeByClowd\Documentable\Drivers\S3MultipartDriver;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | Default Flysystem disk used when a DocumentType doesn't specify its own.
    | Must be an S3-API-compatible disk for multipart uploads to work (S3, R2,
    | MinIO, Spaces, ...). Direct/small-file uploads work on any disk.
    |
    */
    'disk' => env('DOCUMENTABLE_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Migrations
    |--------------------------------------------------------------------------
    |
    | Set to false if you'd rather publish and version the migrations yourself.
    |
    */
    'load_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Set to false to disable auto-registration and mount the package's
    | controllers yourself (custom prefix/middleware/throttle).
    |
    */
    'load_routes' => true,

    /*
    |--------------------------------------------------------------------------
    | Document Types (code-first catalog)
    |--------------------------------------------------------------------------
    |
    | Define document types here (recommended) instead of hand-building admin
    | CRUD for the document_types table. Keyed by `code`. Run
    | `php artisan documents:sync-types` to upsert these into the database.
    |
    | Leave empty and manage the document_types table directly if you need a
    | fully dynamic, runtime-editable catalog instead.
    |
    | Example:
    | 'invoice' => [
    |     'name' => 'Invoice',
    |     'max_size_mb' => 10,
    |     'allowed_mimes' => ['application/pdf'],
    |     'disk' => 's3',
    |     'path_prefix' => 'invoices',
    |     'requires_versioning' => true,
    |     'allows_multiple' => false,
    | ],
    |
    */
    'types' => [
        // ...
    ],

    /*
    |--------------------------------------------------------------------------
    | Multipart Upload
    |--------------------------------------------------------------------------
    |
    | threshold_bytes: files under this size use direct/presigned single PUT,
    | not multipart. AWS's own guidance is multipart only pays off well above
    | ~100MB; default here is conservative.
    |
    | etag_strategy:
    |   - 'client': client captures ETag from each part's presigned PUT
    |     response and reports it back. Fewer round trips. Requires bucket
    |     CORS ExposeHeaders: ["ETag"].
    |   - 'server-authoritative': client reports part numbers only; server
    |     always resolves ETags via ListParts before completing. No CORS
    |     dependency. Needed for SaaS-managed/limited-CORS buckets.
    |
    */
    'multipart' => [
        'threshold_bytes' => 10 * 1024 * 1024,
        'etag_strategy' => 'server-authoritative',
        'part_upload_url_ttl' => '+1 hour',
        'session_ttl_hours' => 24,
        'use_native_checksum' => false,
        'drivers' => [
            's3' => S3MultipartDriver::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle / Orphan Cleanup
    |--------------------------------------------------------------------------
    |
    | pending_ttl_hours: default TTL for documents created in 'pending' status
    | (see MadeByClowd\Documentable\Models\Document::commit()). reaper_frequency
    | is a Schedule fluent-method name ('hourly', 'daily', 'everyFiveMinutes',
    | ...) applied to the documents:clean-orphaned command. Multipart session
    | TTL uses multipart.session_ttl_hours (phase 3) — single source of truth,
    | not duplicated here.
    |
    */
    'lifecycle' => [
        'pending_ttl_hours' => 24,
        'reaper_frequency' => 'hourly',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Bind your own implementation of
    | MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess to control
    | who can upload/view/delete documents. Default is permissive (allows
    | everything) — replace before production use.
    |
    */
    'authorization' => [
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication Scope
    |--------------------------------------------------------------------------
    |
    | Default dedup is global by file hash. Bind your own implementation of
    | MadeByClowd\Documentable\Contracts\ResolvesDedupScope to scope dedup
    | (e.g. per tenant) if hash collisions across tenants are a concern.
    |
    */
    'dedup' => [
        'scope_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Bind your own implementation of
    | MadeByClowd\Documentable\Contracts\ScansUploadedFile (e.g. a ClamAV
    | sidecar call) to reject infected uploads. Default is a no-op that always
    | reports clean — AV scanning infrastructure is out of scope for the
    | package itself, but the hook always runs so wiring one in doesn't
    | require forking.
    |
    | allowed_documentable_types: applied to every documentable_type sent by
    | a client. A type resolved via Relation::morphMap() always passes
    | regardless of this setting. An unmapped type is rejected unless it
    | appears in this array — null means "reject every unmapped type"
    | (the safe default). Without Relation::enforceMorphMap() AND this
    | allowlist, a client can pass any Eloquent model FQCN in the app as
    | documentable_type; the (default-permissive) authorizer is the only
    | other gate. See docs/feedbacks/feedback.md #3.
    |
    */
    'security' => [
        'scanner' => null,
        'allowed_documentable_types' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Path Generation
    |--------------------------------------------------------------------------
    |
    | Bind your own implementation of
    | MadeByClowd\Documentable\Contracts\GeneratesStoragePath to control the
    | storage key newly-stored files are written to (e.g. date/tenant
    | sharding). Default: "{$type->path_prefix}/{uuid}".
    |
    */
    'storage_path' => [
        'generator' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-Disk Server-Side Encryption
    |--------------------------------------------------------------------------
    |
    | Explicit SSE, passed through to every write/presign/multipart-create
    | call rather than relying on the bucket's default encryption setting.
    | server_side_encryption: 'AES256' | 'aws:kms'. kms_key_id only used when
    | server_side_encryption is 'aws:kms'.
    |
    | Example:
    | 's3' => ['server_side_encryption' => 'AES256'],
    |
    */
    'disks' => [
        // 's3' => ['server_side_encryption' => 'AES256', 'kms_key_id' => null],
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Middleware
    |--------------------------------------------------------------------------
    |
    | Applied to every route in routes/api.php, in addition to the throttle
    | middleware below. Default ('api') has no session/auth — $request->user()
    | is null, so AuthorizesDocumentAccess always receives a null $user,
    | created_by/deleted_by stay null, and resolveUserId() falls back to a
    | client-supplied user_id field. Session-based monolith (Inertia,
    | Livewire, classic SPA-on-same-origin): use ['web', 'auth']. Separate
    | token-auth API: use ['api', 'auth:sanctum'] (or your guard). See
    | docs/feedbacks/feedback.md #1 for the failure mode this prevents.
    |
    */
    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Throttle
    |--------------------------------------------------------------------------
    |
    | Named rate limiter the package's routes (phase 7) are mounted behind.
    | Package doesn't hardcode a specific rate — define this limiter
    | (RateLimiter::for('documents', ...)) in your own AppServiceProvider.
    |
    */
    'throttle' => 'documents',

    /*
    |--------------------------------------------------------------------------
    | Audit / Actor Tracking
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => false,
        'access_log' => false,
    ],
];
