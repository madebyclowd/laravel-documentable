---
bump: minor
type: Added
---

`php artisan documents:clean-orphaned --bucket` lists objects under your document types' path prefixes that no stored file or live upload session points at (dry run; `--apply` deletes). It warns when the bucket has versioning on, where a delete only adds a delete marker.
