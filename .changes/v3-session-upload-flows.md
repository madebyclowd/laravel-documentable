---
bump: major
type: Changed
---

Every upload transport now runs on an upload session, and `DocumentService::attach($session, $owner, new AttachOptions(...))` is the only way a session becomes a document. Presigned finalize and multipart complete take a `session_id` instead of a `path` and an `upload_id`, so a client can no longer point them at another object in your bucket. Only the user a session was started under can drive it (403 otherwise), a `document_type_id` that disagrees with the session's type is a 422, and an expired session refuses to complete. A failed check now deletes the object and aborts the session. The owner fields are optional on `POST /documents`, `presigned/finalize` and `multipart/complete`; without them the response is the `uploaded` session, to attach later with the new `POST /documents/attach`. `document_group_id` must name a group the owner already holds. Service signatures changed: `initiatePresignedUpload` replaces `createPresignedUpload`, and `initiateMultipartUpload`, `generatePartUploadUrl`, `listPartsForSession`, `completeMultipartUpload` and `finalizeDirectUpload` take sessions.
