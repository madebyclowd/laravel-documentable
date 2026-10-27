---
bump: major
type: Added
---

New `documentable.key_type` setting (`int`, `uuid`, `ulid` or `string`, default `string`) gives
`documents.documentable_id` the type of your owner models' primary key. A new migration converts an existing
column after checking that every stored key fits, and stops with `php artisan documents:upgrade-check` when one
does not. With a typed key `$model->documents()` is Laravel's stock `MorphMany`, so PostgreSQL needs no casts
and the column index is usable; the default `string` keeps the string-cast relation. `documents:install` asks
for the key type before it runs the migrations (`--key-type` skips the prompt). Converting rewrites the
`documents` table, so run it in a maintenance window on a large MySQL table.
