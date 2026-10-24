---
bump: patch
type: Changed
---

The document events (`DocumentUploaded`, `DocumentDeleted`, `DocumentPurged`, `DocumentReassociated`, `DocumentVersionSuperseded`, `MultipartUploadInitiated`, `MultipartUploadAborted`) now implement `ShouldDispatchAfterCommit`. They fire immediately outside a transaction and after the outermost commit inside one; a rolled-back transaction drops them. This is a behavior change: a listener can no longer abort the surrounding transaction by throwing, and one that relied on running inside it now runs after it. `documents:sync-types` now reports the types it created and updated, lists types that exist only in the database, and `php artisan about` shows how config and the table compare. Shipped comments no longer point at internal planning documents (a PHPUnit test and a CI step enforce it), and the README documents event timing, the `throw => true` disk setting, the deploy steps, and why `use_native_checksum` should stay off.
