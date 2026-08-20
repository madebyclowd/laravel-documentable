---
bump: patch
type: Fixed
---

`S3MultipartDriver::presignPartUpload()` now respects the disk's `temporary_url`
config, matching `getUrl()` and `createPresignedUpload()`. Previously it signed
directly against the raw SDK client and returned the internal endpoint
unconditionally, so multipart uploads (files over `multipart.threshold_bytes`,
10MB by default) silently bypassed `temporary_url` while small-file uploads
respected it — breaking part-upload URLs for any setup relying on
`temporary_url` to expose a different public endpoint than the internal one
(e.g. MinIO/S3-compatible storage behind a reverse proxy).
