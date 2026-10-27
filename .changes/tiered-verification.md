---
bump: major
type: Changed
---

Large uploads are no longer hashed inside the request. An object above `verification.sync_hash_max_bytes`
(default 50 MiB) is checked for size and content type from its first bytes, its document is created with the new
`processing` status, and a queued job (`VerifyUploadedObject`) streams the hash, applies the client's hash, the
type rules and the scanner, then commits the document or rejects it and removes the object. `processing` and
`rejected` documents have no download URL (`DocumentNotReady`, HTTP 409) and are never a dedup target, and a
rejected replacement gives the slot back to the previous document. New events: `DocumentProcessed` and
`DocumentRejected`. Handle the `processing` status in your UI, and run a queue worker; with the `sync` queue
driver the job runs inside the request. A scanner outage is retried rather than treated as a verdict.
