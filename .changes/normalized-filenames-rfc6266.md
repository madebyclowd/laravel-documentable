---
bump: major
type: Changed
---

`client_filename` is now normalized when the document is written: the directory part, control characters,
leading dots and trailing dots or spaces are removed, invalid UTF-8 is dropped and the length is capped at 255
bytes keeping the extension. The name the client sent is kept in `metadata.original_filename` when it differs, and
the extension rule judges the normalized name. Download URLs now send an RFC 6266 `Content-Disposition`: an ASCII
`filename` for old clients plus `filename*` with the real UTF-8 name, so a browser saves "résumé.pdf" as written
instead of an underscore. `UrlSigner::sanitizeHeaderFilename()` also replaces a backslash with `_`, and a name that
sanitizes to nothing is sent as `download` instead of an empty `filename=""`.
