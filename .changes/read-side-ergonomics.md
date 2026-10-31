---
bump: minor
type: Added
---

Read-side ergonomics and download endpoints:
- Model API: Document accessors for filename, size, mime, and extension, a lazy temporaryUrl() helper delegating to DocumentService::getUrl(), and query scopes for latest(), committed(), and ofType().
- Documentable trait helpers: documentOfType() MorphOne relation and documentsOfType() MorphMany relation respecting owner key types with eager-loading support.
- URLs: UrlOptions value object with inline content-type allowlist, disposition validation, TTL clamping, ModifiesUrlOptions hook contract, and container-resolved modifier config.
- Download routes: GET /documents/{document}/download and /view redirecting to presigned URLs with Cache-Control: no-store and access log integration.
- Resources: DocumentResource and DocumentCollection with opt-in ->withUrl() support.
