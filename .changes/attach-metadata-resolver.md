---
bump: minor
type: Added
---

`ResolvesAttachMetadata`, set as `attach.metadata_resolver`, lets the server stamp document metadata as an upload is attached, for every upload transport. Its keys replace the client's. `size`, `mime_type` and `original_filename` are now always set by the package and can no longer be overwritten by the client's `metadata`.
