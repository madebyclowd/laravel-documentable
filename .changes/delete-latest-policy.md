---
bump: major
type: Changed
---

Deleting the latest version of a document group now promotes the highest remaining committed version to latest,
instead of leaving the group with no latest (and the next upload starting a new group at v1). Set the document
type's `on_delete_latest` to `none` for the old behavior, or `block` to refuse the delete while older versions exist
(`CannotDeleteLatestVersion`). `document_types.max_versions_to_keep`, which nothing read, is renamed `versions_kept`
and now works; the migration carries existing values over.
