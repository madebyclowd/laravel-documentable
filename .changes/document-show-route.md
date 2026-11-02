---
bump: minor
type: Added
---

`GET /documents/{document}` returns a document with its status, so a client can wait for a `processing` document to turn `committed` or `rejected`. It is gated by `canView`.
