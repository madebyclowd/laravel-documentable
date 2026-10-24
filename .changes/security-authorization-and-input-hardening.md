---
bump: patch
type: Security
---

The multipart follow-up endpoints (`part-url`, `parts`, `status`, `abort`) now call `AuthorizesDocumentAccess::canUpload()` for the session's document type, as `initiate` and `complete` already did, and `GET /documents/types` lists only the types the authorizer allows. A request-body `user_id` is no longer accepted as the multipart session owner for unauthenticated callers unless `documentable.security.allow_guest_user_id` is `true`; guest or device flows that relied on it must set that key. `GET /documents/{document}/url` validates its query: `disposition` is `inline` or `attachment`, `expires_in_minutes` is 1 to `documentable.url.max_ttl_minutes` (default 1440), and an expired `pending` document returns 404. Booting in production with the routes mounted and the permissive authorizer now logs a warning and appears in `php artisan about`.
