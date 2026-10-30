---
bump: minor
type: Added
---

`php artisan documents:doctor {disk}` checks a bucket before you deploy: presigned PUT and GET, multipart create with and without metadata, `UploadPart`, the `ETag` header, ranged reads, delete, lifecycle rules, and whether the provider rejects the AWS SDK default checksum headers. Each failure prints its fix, and `documents:install` offers to run it.
