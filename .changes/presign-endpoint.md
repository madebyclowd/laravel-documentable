---
bump: major
type: Changed
---

Download, single-PUT and multipart part URLs are now signed locally by a separate client against the optional `documentable.disks.{disk}.presign_endpoint`, instead of rewriting `temporary_url` after signing, so they stay valid behind a proxy that does not forward the original `Host`. `CreateMultipartUpload` no longer sends filename metadata (opt in with `multipart.object_metadata`, URL-encoded) or forces a `Content-Type` (opt in with `multipart.content_type`).
