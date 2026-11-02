---
bump: minor
type: Added
---

`GET /documents/config` tells a browser uploader what the server already knows: the multipart threshold, part size bounds, upload concurrency, the retry policy, the hashing limit and the types the caller may upload to. The concurrency and retry policy come from the new `client` config section.
