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
    | Audit / Actor Tracking
    |--------------------------------------------------------------------------
    */
    'audit' => [
        'enabled' => false,
        'access_log' => false,
    ],
];
