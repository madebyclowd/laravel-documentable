---
bump: patch
type: Fixed
---

Completing a multipart upload now always sends the parts to S3 as a plain list, even when the caller keyed them by part number. Previously a keyed array produced a request S3 could not read.
