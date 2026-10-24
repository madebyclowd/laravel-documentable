---
bump: patch
type: Fixed
---

`$owner->documents` and its eager, `whereHas` and `withCount` forms failed on PostgreSQL for an owner with an integer primary key (`operator does not exist: character varying = integer`), because `documents.documentable_id` is a string column. `documents()` now returns a relation that sends the owner key as a string, so the `idAsString` accessors and `$keyType = 'string'` workarounds can be removed. Documents made through the relation also store the key as a string. SQLite and MySQL behave as before.
