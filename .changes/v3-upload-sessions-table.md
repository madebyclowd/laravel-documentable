---
bump: major
type: Added
---

Adds the `upload_sessions` table, the `UploadSession` model, the `UploadSessionStatus` and `UploadKind` enums, `UploadSessionData` and `AttachOptions`. A session is the server-side record of one upload in progress: its ULID is the only handle a client holds, and the storage path and provider upload id stay on the server. Publish and run the new migrations with `php artisan migrate`.
