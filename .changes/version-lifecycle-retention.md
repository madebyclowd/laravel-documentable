---
bump: minor
type: Added
---

`DocumentService::restore()`, `deleteGroup()` and `promote()` manage versions, and `DocumentVersionPromoted` fires
when a version becomes latest. Document types can prune old versions with `versions_kept` and `versions_retain_days`:
a non-latest version is deleted only when it is beyond the count and older than the age, and a missing setting
leaves that axis alone. The `PruneDocumentVersions` job runs after the upload commits and dispatches
`DocumentVersionPruned`. `documents:prune-versions {--type=} {--dry-run}` runs the same selection on demand, and
`documentable.retention.hold` lists document or group ids that are never pruned.
