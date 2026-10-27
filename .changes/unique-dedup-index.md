---
bump: major
type: Changed
---

`storage_files` now has a unique `(file_hash, disk)` index, so concurrent uploads of identical bytes create one
stored file and the loser adopts the winner's and deletes its own copy. The dedup lookup includes the disk, which
stops a type on one disk from resolving to a blob stored on another. `StorageFileRepository::findByHash()` takes
the disk as a second argument. The new migration stops with the commands to run (`documents:upgrade-check`,
`documents:repair`) while duplicate stored files exist.
