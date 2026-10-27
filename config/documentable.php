<?php

declare(strict_types=1);

use MadeByClowd\Documentable\Drivers\S3MultipartDriver;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Disk
    |--------------------------------------------------------------------------
    |
    | Default Flysystem disk used when a DocumentType doesn't specify its own.
    | Must support Storage::temporaryUrl() (S3, R2, MinIO, Spaces, ... or any
    | disk with a registered Storage::buildTemporaryUrlsUsing() callback) —
    | GET /documents/{id}/url calls temporaryUrl() for every document
    | regardless of size, not just multipart-uploaded ones. Uploading via
    | upload() and the staged upload work on any disk (e.g. local); *viewing* a
    | document after upload does not, unless the disk supports temporary URLs.
    |
    */
    'disk' => env('DOCUMENTABLE_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Owner Key Type
    |--------------------------------------------------------------------------
    |
    | The column type of documents.documentable_id, the primary key of the
    | models that own documents. One of: int, uuid, ulid, string.
    |
    | Match it when every owner model shares one key type: queries then use the
    | column's index, and PostgreSQL needs no casts. Keep 'string' when owners
    | have different key types (an int User and a uuid Invoice, say), because
    | one column cannot hold both.
    |
    | Changing it on an install that already has documents converts the column
    | with a migration. Run `php artisan documents:upgrade-check` first. On
    | MySQL the conversion rewrites the table and blocks writes while it runs.
    |
    */
    'key_type' => 'string',

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
        // Leave false. S3 multipart checksums are composite (a checksum of the part checksums), so
        // the value this reads back never equals the file's SHA-256: an upload with an expected_hash
        // is rejected, and one without stores a hash that breaks deduplication. Slated for removal.
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
    | reaper_frequency is a Schedule fluent-method name ('hourly', 'daily',
    | 'everyFiveMinutes', ...) applied to the documents:clean-orphaned command,
    | which expires upload sessions past their expiry and releases what they
    | hold. How long a session lives is multipart.session_ttl_hours, for every
    | kind of upload — single source of truth, not duplicated here.
    |
    */
    'lifecycle' => [
        'reaper_frequency' => 'hourly',

        // `documents:clean-orphaned --storage-files` only touches stored files older than this, so a file
        // whose document is still being created in the same upload is never swept.
        'storage_file_grace_hours' => 24,
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
    | other gate.
    |
    */
    'security' => [
        'scanner' => null,
        'allowed_documentable_types' => null,

        // Discouraged. When true and nobody is authenticated, the multipart endpoints accept `user_id`
        // from the request body as the session owner. Anyone who can reach the route can then name any
        // owner, so only enable it for a device/guest flow you protect another way (signed URLs, a
        // device token checked by your own middleware). Authenticated requests always use the real user.
        'allow_guest_user_id' => false,

        // What to do when the scanner returns ScanResult::Error (it could not reach a verdict). `reject`
        // (default) refuses the file, so a scanner outage cannot silently turn scanning off. `allow` stores
        // it and logs a warning; only choose it if availability matters more than scanning.
        'scan_error_policy' => 'reject',
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary URLs
    |--------------------------------------------------------------------------
    |
    | Upper bound, in minutes, a client may ask for through
    | GET /documents/{document}/url?expires_in_minutes=. The default request is 5 minutes.
    |
    */
    'url' => [
        'max_ttl_minutes' => 1440,
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
    | created_by/deleted_by stay null, and the multipart endpoints reject the
    | request unless security.allow_guest_user_id lets a client-supplied
    | user_id stand in as the session owner. Session-based monolith (Inertia,
    | Livewire, classic SPA-on-same-origin): use ['web', 'auth']. Separate
    | token-auth API: use ['api', 'auth:sanctum'] (or your guard). With only
    | ['api'] there is no session or auth, so $request->user() is null on
    | every request.
    |
    */
    'middleware' => ['api'],

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    |
    | Default page size for GET /documents (owner-scoped, grouped by
    | document_type_id then document_group_id). The owner's latest documents are
    | loaded, canView() is applied to each in PHP, and only then is the result
    | paged, so `total` counts just what the caller may view. For an owner with
    | thousands of documents, list a narrower set with document_type_id.
    |
    */
    'listing' => [
        'per_page' => 50,
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttle
    |--------------------------------------------------------------------------
    |
    | Named rate limiter the package's routes are mounted behind.
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
