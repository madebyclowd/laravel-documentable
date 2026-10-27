---
bump: major
type: Added
---

A document type can now limit an upload by extension (`allowed_extensions`, a list of lowercase extensions) and
accept files fileinfo can only call `application/octet-stream` or `text/plain` when the extension is on that list
(`trust_extension_for_generic_mime`; a specific MIME type that is not allowed is never excused). Content validators
registered per type under `documentable.validators` read the file's first bytes: `DwgValidator`, `DxfValidator`,
`ZipValidator` (docx, xlsx, pptx), `OleDocumentValidator`, `SevenZipValidator`, `RarValidator` and
`GzipValidator`, or your own `ValidatesUploadedFile`. The rules apply to every transport, including a large upload
before it is queued. `GET /documents/types` returns the two new fields. Run the new migration.
