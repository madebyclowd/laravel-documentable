---
bump: patch
type: Security
---

A failed `POST /documents/presigned/finalize` (`finalizeDirectUpload()`) no longer deletes the object it was pointed at. Because `path` is supplied by the client, a wrong `expected_hash` or a size/mime rejection used to delete any key a caller named, and finalizing the same path twice deleted the live blob. Rejected uploads now stay in the bucket, so add a lifecycle expiration rule for your upload prefixes. Finalizing a path that already backs a stored file is refused, and `path` must be a relative key (no leading `/`, no `..`, no backslash, at most 1,024 characters). New opt-in `documentable.security.enforce_path_prefix` restricts a finalize to the document type's `path_prefix`. A caller who passes `canUpload` can still adopt an existing bucket key that no stored file uses, until the v3 upload sessions land, so keep the bucket private to this package.
