---
bump: patch
type: Fixed
---

`documents:upgrade-check` and `documents:repair` run on a v2 database before `migrate`, as UPGRADE.md orders them. They no longer fail with `relation "upload_sessions" does not exist`; a missing sessions table is treated as holding nothing.
