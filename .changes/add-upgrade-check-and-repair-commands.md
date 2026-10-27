---
bump: major
type: Added
---

`php artisan documents:upgrade-check` reports data that would block the new integrity constraints: more than one
latest document for an owner and single-slot type, several stored files sharing a `(file_hash, disk)` pair, and
documents that point at a missing stored file. `php artisan documents:repair --dry-run` prints the fix and
`--apply` runs it: the highest version (newest on a tie) stays latest, duplicate stored files are merged, and a
blob is only deleted when no other row uses it.
