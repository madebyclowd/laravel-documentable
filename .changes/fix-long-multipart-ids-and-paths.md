---
bump: patch
type: Fixed
---

Starting a multipart upload on Cloudflare R2 failed on PostgreSQL (and on MySQL in strict mode) with `value too long for type character varying(255)`: R2 upload ids are 343 characters. Two new migrations widen `multipart_uploads.path`, `multipart_uploads.upload_id` and `storage_files.path` to `text`, replace the `(path, upload_id, user_id)` index with one on `user_id`, and skip anything already widened, so run `php artisan migrate`. Session lookup is now case-sensitive on every engine. CI runs the MySQL and PostgreSQL legs on Laravel 11 and 12 as well.
