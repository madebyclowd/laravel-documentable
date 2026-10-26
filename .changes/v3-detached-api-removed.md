---
bump: major
type: Removed
---

Removes `DocumentService::uploadDetached()`, `reassociateDocument()` and `Document::commit()`, the `pending` document status and scope, the `lifecycle.pending_ttl_hours` and `security.enforce_path_prefix` options, `POST /documents/detached`, the `GET /documents/multipart/status` fields `path` and `upload_id`, the `DocumentReassociated` event, and the `MultipartUpload` model and `multipart_uploads` table. Replace them with `stageUpload()` (or `POST /documents` without owner fields) followed by `attach()`. Document statuses are now `committed`, `processing` and `rejected` (`DocumentStatus`). The migration that drops `multipart_uploads` refuses to run while an unexpired session is open; set `DOCUMENTABLE_FORCE_DRAIN=true` to abort those provider uploads and drop the table. Open `pending` documents are converted to `uploaded` sessions; expired ones are deleted.
