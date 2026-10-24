## laravel-documentable

S3-compatible document storage for Eloquent models: content-addressed dedup, composable versioning
(`allows_multiple` x `requires_versioning`), presigned direct-PUT and multipart uploads, orphan cleanup,
and pluggable authorization, scanning and storage-path contracts. Every write goes through
`MadeByClowd\Documentable\Services\DocumentService`.

### Core conventions

- Use `MadeByClowd\Documentable\Traits\Documentable` on the owning model (`documents()` relation), or run
  `php artisan documents:attach-model {Model}`. Never insert into `documents` or `storage_files` yourself.
- `DocumentType` rows define the upload slots (size, mimes, disk, `allows_multiple`, `requires_versioning`).
  Define them in `config('documentable.types')` and run `php artisan documents:sync-types` on every deploy;
  do not hand-build admin CRUD for the table.
- Bind a real `AuthorizesDocumentAccess` (`documents:make-authorizer`) via
  `config('documentable.authorization.resolver')`. The default allows everything. `canUpload()` receives a
  `null` `$documentable` from detached uploads and multipart `initiate`, so decide those on `$type` and the
  user, never with `return true`.
- Call `Relation::enforceMorphMap()` and send the map alias, not the class name, as `documentable_type`.
  Unmapped types are rejected unless listed in `security.allowed_documentable_types`.
- Keep `security.allow_guest_user_id` false and define a `RateLimiter::for('documents', ...)`. Routes mount
  under `config('documentable.middleware')`, default `['api']`, which has no auth, so pass `--shape=` to
  `documents:install` in scripts.

### Operational commands

- `documents:install`, `documents:sync-types [--prune]`, `documents:list`, `documents:verify [--repair]`.
- `documents:clean-orphaned [--hours=N] [--storage-files]` is the reaper (auto-scheduled).
- `documents:configure-bucket-cors {disk} --origin=...` and `documents:configure-bucket-lifecycle {disk}`.

### Pitfalls

- Set `'throw' => true` on the documents disk: `purge()` returns `false` and keeps the `StorageFile` row
  when a delete fails, and a `throw => false` disk hides other failures.
- Document events fire after the outermost commit. Queued listeners should implement
  `ShouldQueueAfterCommit`; a listener can no longer abort the transaction by throwing.
- A failed presigned finalize never deletes the object it was given, so add a bucket lifecycle rule for
  upload prefixes. Leave `use_native_checksum` false: S3 multipart checksums are composite and never equal
  the file's SHA-256.
- Run `php artisan boost:update` after upgrading the package; consuming apps hold snapshots of these files.

See the `laravel-documentable` Agent Skill (installed alongside this guideline) for upload transports,
versioning semantics, the security checklist and troubleshooting.
