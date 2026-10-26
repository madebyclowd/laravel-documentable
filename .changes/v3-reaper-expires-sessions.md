---
bump: major
type: Changed
---

`documents:clean-orphaned` now expires upload sessions past their expiry instead of sweeping `pending` documents and `multipart_uploads`. Expiring a session aborts an open multipart upload on the provider, or deletes the object it holds, unless the session was attached in the meantime. `--hours` expires any open session older than that.
