---
bump: major
type: Added
---

Adds the `upload_sessions` table, the `UploadSession` model, the `UploadSessionStatus` and `UploadKind` enums, and `UploadSessionData`. A session is the server-side record of one upload in progress: its ULID is the only handle a client will hold, and the storage path and provider upload id stay on the server. Nothing uses sessions yet; the upload flows move onto them in a following release, and the `multipart_uploads` table is removed then. Publish and run the new migration with `php artisan migrate`.
