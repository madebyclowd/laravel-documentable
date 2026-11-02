# Configuration reference

Published to [`config/documentable.php`](../config/documentable.php):

```php
return [
    // Default Flysystem disk name
    'disk' => env('DOCUMENTABLE_DISK', 's3'),

    // Owner key column type: 'string', 'int', 'uuid', or 'ulid'
    'key_type' => 'string',

    // Whether to auto-load package migrations
    'load_migrations' => true,

    // Whether to register package HTTP routes
    'load_routes' => true,

    // The path the routes are mounted under
    'route_prefix' => 'documents',

    // Code-first document types catalog
    'types' => [
        // 'invoice' => [...]
    ],

    // Content validators for custom format verification
    'validators' => [],

    // Multipart upload configuration
    'multipart' => [
        'threshold_bytes' => 10 * 1024 * 1024,
        'etag_strategy' => 'server-authoritative', // 'server-authoritative' or 'client'
        'part_upload_url_ttl' => '+1 hour',
        'session_ttl_hours' => 24,
        'drivers' => [
            's3' => \MadeByClowd\Documentable\Drivers\S3MultipartDriver::class,
        ],
        'object_metadata' => false,
        'content_type' => null,
    ],

    // Browser client concurrency and retry defaults
    'client' => [
        'max_concurrency' => 4,
        'retry' => [
            'attempts' => 3,
            'base_delay_ms' => 500,
            'max_delay_ms' => 8000,
        ],
    ],

    // Tiered verification settings
    'verification' => [
        'sync_hash_max_bytes' => 50 * 1024 * 1024, // 50MB inline limit
        'connection' => null,
        'queue' => null,
    ],

    // Orphan cleanup and reaper settings
    'lifecycle' => [
        'reaper_frequency' => 'hourly',
        'storage_file_grace_hours' => 24,
    ],

    // Authorization resolver contract
    'authorization' => [
        'resolver' => null, // Required in production
    ],

    // Deduplication scoping and handshake
    'dedup' => [
        'scope_resolver' => null,
        'challenge_ttl' => 300,
        'max_range_bytes' => 64 * 1024,
        'throttle' => 'documents-handshake',
    ],

    // Security policies
    'security' => [
        'scanner' => null,
        'allowed_documentable_types' => null,
        'scan_error_policy' => 'reject', // 'reject' or 'allow'
    ],

    // Temporary URL options
    'url' => [
        'max_ttl_minutes' => 1440,
        'modifier' => null,
    ],

    // Storage path generator
    'storage_path' => [
        'generator' => null,
    ],

    // Per-disk S3 client overrides
    'disks' => [
        // 's3' => ['presign_endpoint' => 'https://...', 'server_side_encryption' => 'AES256'],
    ],

    // Route middleware stack
    'middleware' => ['api'], // Use ['web', 'auth'] for session monoliths

    // Pagination for GET /documents
    'listing' => [
        'per_page' => 50,
    ],

    // Route rate limiter name
    'throttle' => 'documents',

    // Audit and access logging
    'audit' => [
        'enabled' => false,
        'access_log' => false,
    ],

    // Version retention hold list
    'retention' => [
        'hold' => [],
    ],

    // Storage doctor diagnostic prefix
    'doctor' => [
        'scratch_prefix' => 'documentable-doctor',
    ],
];
```
