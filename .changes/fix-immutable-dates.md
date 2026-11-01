---
bump: patch
type: Fixed
---

Uploads, staged uploads, attach and `documents:clean-orphaned` no longer throw a `TypeError` in an app that calls `Date::use(CarbonImmutable::class)`. `DirectUploadService` and `UploadSessionRepository` now accept any `CarbonInterface`.
