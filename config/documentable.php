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
    | Content Validators
    |--------------------------------------------------------------------------
    |
    | Checks on what an upload really is, run on its first bytes, per document
    | type code. A type can also set `allowed_extensions` (a list of lowercase
    | extensions) and `trust_extension_for_generic_mime` in its definition above.
    | Built in: DwgValidator, DxfValidator, ZipValidator (also docx, xlsx, pptx),
    | OleDocumentValidator (doc, xls, ppt), SevenZipValidator, RarValidator and
    | GzipValidator. Write your own by implementing ValidatesUploadedFile.
    |
    | 'validators' => [
    |     'DRAWING' => [\MadeByClowd\Documentable\Validators\DwgValidator::class],
    | ],
    |
    */
    'validators' => [
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
    | object_metadata: when true, CreateMultipartUpload sends the URL-encoded
    | original filename in object metadata. Default is false (sends no metadata).
    |
    | content_type: MIME type sent on CreateMultipartUpload when configured.
    | Default is null (does not force ContentType).
    |
    */
    'multipart' => [
        'threshold_bytes' => 10 * 1024 * 1024,
        'etag_strategy' => 'server-authoritative',
        'part_upload_url_ttl' => '+1 hour',
        'session_ttl_hours' => 24,
        'drivers' => [
            's3' => S3MultipartDriver::class,
        ],
        'object_metadata' => false,
        'content_type' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification
    |--------------------------------------------------------------------------
    |
    | Every upload is checked before it can be served: its size, its real
    | content type, the client's hash if one was declared, and the scanner. The
    | hash needs the whole object read once.
    |
    | sync_hash_max_bytes: an object up to this size is verified inside the
    | request. A larger one is only checked for size and content type from its
    | first bytes, its document is created as `processing`, and a queued job
    | hashes and scans it, then marks it `committed` or `rejected`. A
    | `processing` document cannot be downloaded or deduplicated against. Needs
    | a queue worker; with the `sync` queue driver the job runs in the request.
    | The read is bound by bandwidth to storage, not by the CPU: a 1 GiB object
    | hashes in about a second from storage on the same network, but a 50 MiB
    | object took 27 to 39 seconds to hash over the public internet from a
    | development machine. Size the limit to the link between your servers and
    | the bucket. Set 0 to always verify in a job.
    |
    | connection / queue: where the job runs. null uses the application default.
    |
    */
    'verification' => [
        'sync_hash_max_bytes' => 50 * 1024 * 1024,
        'connection' => null,
        'queue' => null,
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
    | who can upload, attach, view and delete documents. Left null, the
    | Gate-backed default (Defaults\GateDocumentAuthorizer) is used: it asks
    | the abilities documents.initiate-upload, documents.finalize,
    | documents.attach, documents.view and documents.delete, and denies any
    | the app has not defined, so nothing is allowed until you define them.
    |
    | In production, booting with the routes mounted and this key null throws
    | InsecureConfiguration. Run `php artisan documents:make-authorizer` to
    | scaffold an authorizer, then set the key to its class. To keep the
    | Gate-backed default, set it to
    | MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer::class.
    |
    */
    'authorization' => [
        'resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication Scope & Proof-of-Possession Handshake
    |--------------------------------------------------------------------------
    |
    | Default dedup is TypeAndTenantScope (scopes by document type and tenant).
    | Bind GlobalDedupScope to opt into global content-hash deduplication across
    | all slots and tenants.
    |
    | challenge_ttl: time in seconds a handshake challenge remains valid (default 300).
    | max_range_bytes: upper bound on byte ranges requested in challenges (default 64 KiB).
    | throttle: named rate limiter for handshake endpoints (default 'documents-handshake').
    |
    */
    'dedup' => [
        'scope_resolver' => null,
        'challenge_ttl' => 300,
        'max_range_bytes' => 64 * 1024,
        'throttle' => 'documents-handshake',
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
    | documentable_type; the authorizer is the only
    | other gate.
    |
    */
    'security' => [
        'scanner' => null,
        'allowed_documentable_types' => null,

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
    | Per-Disk Storage Settings
    |--------------------------------------------------------------------------
    |
    | Configuration options for specific disks.
    | - server_side_encryption: 'AES256' | 'aws:kms'.
    | - kms_key_id: KMS key ARN or alias when server_side_encryption is 'aws:kms'.
    | - presign_endpoint: optional public URL used when generating presigned
    |   download and upload URLs, leaving internal traffic on the disk's endpoint.
    | - presign_use_path_style: optional boolean overriding path-style endpoint
    |   behavior for presigned URLs.
    |
    | Example:
    | 's3' => [
    |     'server_side_encryption' => 'AES256',
    |     'presign_endpoint' => 'https://downloads.example.com',
    |     'presign_use_path_style' => false,
    | ],
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
    | middleware below. Default ('api') has no session/auth, so $request->user()
    | is null on every request: AuthorizesDocumentAccess receives a null $user,
    | created_by/deleted_by stay null and the multipart endpoints refuse the
    | request, because a session needs a real user to belong to. Session-based
    | monolith (Inertia, Livewire, classic SPA-on-same-origin): use
    | ['web', 'auth']. Separate token-auth API: use ['api', 'auth:sanctum'] (or
    | your guard).
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
    | paged in SQL in a stable order, then AuthorizesDocumentAccess::canViewMany()
    | is asked once for that page. A page can therefore hold fewer than per_page
    | documents when some are hidden from the caller; `total` counts the owner's
    | documents, not only those the caller may view.
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
    | Named rate limiter the package's routes are mounted behind. When the app
    | defines no limiter of this name, the package registers one that allows 60
    | requests per minute per user, or per IP address for guests. Define your own
    | (RateLimiter::for('documents', ...)) in your AppServiceProvider to change
    | the rate.
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

    /*
    |--------------------------------------------------------------------------
    | Version Retention & Pruning
    |--------------------------------------------------------------------------
    |
    | Hold list of document IDs and/or document group IDs that must never be
    | pruned by automatic retention or the prune-versions command.
    |
    */
    'retention' => [
        'hold' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage Doctor
    |--------------------------------------------------------------------------
    |
    | Scratch prefix under which php artisan documents:doctor performs read/write
    | probes. Always cleaned up after probes finish.
    |
    */
    'doctor' => [
        'scratch_prefix' => 'documentable-doctor',
    ],
];
