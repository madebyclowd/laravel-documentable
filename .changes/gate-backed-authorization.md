---
bump: major
type: Security
---

Authorization is now deny by default. With no authorizer configured the package asks Laravel's Gate
(`Defaults\GateDocumentAuthorizer`) for `documents.initiate-upload`, `documents.finalize`, `documents.attach`,
`documents.view` and `documents.delete`, and denies every ability your app has not defined; where no Gate ability
exists it asks a registered policy for `initiateUpload`, `finalize`, `attach`, `view` or `delete`, and a Document
policy's `viewMany` answers a whole listing page at once. `PermissiveDocumentAuthorizer` is removed.

`AuthorizesDocumentAccess::canUpload(?Authenticatable, DocumentType, ?Model)`, whose null owner meant three
different things, is replaced by `canInitiateUpload($user, $type)`, `canFinalize($user, $session)` (asked for every
follow-up on an upload session, so an app can finally refuse a finalize), `canAttach($user, $sessionOrDocument,
$owner)` and `canViewMany($user, $documents)`, next to `canView` and `canDelete`.

Booting in production with the routes mounted and no `documentable.authorization.resolver` now throws
`InsecureConfiguration`; local, testing and `load_routes = false` boot as before. `documents:make-authorizer
--style=gates|class` scaffolds either style and `documents:install` scaffolds one by default (`--authorizer`
skips the prompt). The `documents` rate limiter defaults to 60 requests a minute per user or IP instead of
unlimited. A `user_id` sent by the client is never used as an upload owner, and `security.allow_guest_user_id` is
removed: multipart uploads require an authenticated user.

`GET /documents` pages in SQL in a stable order and asks `canViewMany()` once for the page, so `meta.total`
counts the owner's documents rather than only those the caller may view, and a page can hold fewer than
`per_page` documents when some are hidden.
