# Operations

## Storage doctor

Run diagnostic probes against your storage bucket before deploying:

```bash
php artisan documents:doctor s3
```

Checks permissions, bucket connectivity, CORS configuration, multipart support, and checksum handling.

## Orphaned upload reaper

The reaper expires stalled upload sessions and aborts dangling provider multipart uploads:

```bash
php artisan documents:clean-orphaned
```

- Run on a schedule via `config('documentable.lifecycle.reaper_frequency')` (default `hourly`).
- Pass `--hours=N` to override the age threshold.
- Pass `--storage-files` to retry deleting unreferenced stored files.
- Pass `--bucket` to inspect untracked objects directly in your bucket under type prefixes (add `--apply` to delete).

## Integrity verification and repair

Inspect database integrity for latest marker and slot invariants:

```bash
php artisan documents:verify
```

Preview and apply automated integrity repairs:

```bash
php artisan documents:repair --dry-run
php artisan documents:repair --apply
```

## Bucket configuration

Configure bucket CORS and lifecycle rules directly from Artisan:

```bash
# Configure bucket CORS:
php artisan documents:configure-bucket-cors s3 --origin=https://app.example.com --verify

# Configure bucket lifecycle rules:
php artisan documents:configure-bucket-lifecycle s3 --days=3
```

## Provider notes

This table compares multipart behavior across S3-compatible providers:

| Provider | Minimum part size (except last) | Max parts | Equal part sizes required | Upload ID length | Single PutObject SHA-256 verification | Browser needs CORS ExposeHeaders ETag | Unfinished uploads auto-expire |
|---|---|---|---|---|---|---|---|
| AWS S3 | 5 MiB | 10,000 | no | short | accepted | yes | lifecycle rule required |
| Cloudflare R2 | 5 MiB | 10,000 | yes (fixed part size) | 343 chars | yes (BadDigest) | yes | lifecycle rule required |
| MinIO / RustFS | 5 MiB | 10,000 | no | short | yes | yes | lifecycle rule required |
| Backblaze B2 | 5 MB | 10,000 | no | variable | accepted | yes | lifecycle rule required |
| DO Spaces | 5 MiB | 10,000 | no | variable | accepted | yes | lifecycle rule required |

The Cloudflare R2 and MinIO / RustFS rows were checked against real buckets. The other rows come from provider documentation and have not been run against a real bucket.

## Behind a proxy

When clients reach storage at a different address than your application servers do, configure `presign_endpoint` under `disks` in `config/documentable.php`:

```php
'disks' => [
    's3' => [
        'presign_endpoint' => 'https://downloads.example.com',
        'presign_use_path_style' => false,
    ],
],
```

A dedicated client signs download and upload URLs for that host, while server-side reads and deletes keep using the disk's internal endpoint.

## Deploy checklist

1. Run `php artisan documents:sync-types` on every deploy next to `php artisan migrate`.
2. Set `'throw' => true` on the disk configuration in `config/filesystems.php`.
3. Run `php artisan documents:configure-bucket-lifecycle {disk}` once per bucket.
4. Define a rate limiter for `documentable.throttle` in `AppServiceProvider`.
5. Ensure a queue worker is active to process background verification for documents in `processing` status.
