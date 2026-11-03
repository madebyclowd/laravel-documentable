---
bump: minor
type: Fixed
---

`uploadFile()` no longer throws `TypeError: Cannot read properties of null (reading 'length')` for a document type without an extension limit. `GET /documents/config` now sends `[]` instead of `null` for `allowed_mimes` and `allowed_extensions`, and the client accepts both. Anything unexpected inside `uploadFile()` now leaves as an `UploadError` with code `server`, so callers can branch on `error.code`. The built-in SHA-256 no longer allocates per 64-byte block, which makes hashing about 3.4 times faster (46 to about 160 MB/s on a 256 MB buffer in Node), so a 500 MB file spends about 3 s hashing instead of 11 s.
