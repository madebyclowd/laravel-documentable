---
bump: patch
type: Fixed
---

`reassociateDocument()` now resolves the slot like an upload: moving a document into a single-slot type its new owner already holds makes it the next version (or replaces the old one for a type without versioning) and dispatches `DocumentVersionSuperseded`, instead of leaving two `is_latest` rows. `purge()` now returns `false`, logs an error and keeps the `StorageFile` row when the blob cannot be deleted, and `documents:clean-orphaned --storage-files` retries those. Both are behavior changes: a caller that ignored the old `true` after a failed delete will now see `false`. A multipart session can no longer be completed, listed, presigned or aborted under a different document type than it was started with (422). The reaper no longer stops at an upload the provider has already dropped (`NoSuchUpload`), logs and continues past any other per-session failure, and exits non-zero if one failed. `documents:sync-types` warns about disks with `throw => false`.
