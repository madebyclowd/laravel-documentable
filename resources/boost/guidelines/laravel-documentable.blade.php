## laravel-documentable

S3-compatible document storage for Eloquent models: content-addressed dedup, composable versioning
(`allows_multiple` x `requires_versioning`), upload sessions, presigned direct-PUT and multipart uploads,
orphan cleanup, and pluggable authorization contracts. Every write goes through
`MadeByClowd\Documentable\Services\DocumentService`.

### Core conventions

- Use `MadeByClowd\Documentable\Traits\Documentable` on owning models (`documents()` relation), or run
  `php artisan documents:attach-model {Model}`. Never insert into `documents` or `storage_files` directly.
- Uploads yield an `UploadSession`. Attach a completed session to an owner with `attach($session, $owner)`.
- Large uploads verify in the background; handle `DocumentStatus::Processing` until verified and committed.
- `DocumentType` records define upload rules (size, mimes, disk, slots, retention). Define them in
  `config('documentable.types')` and run `php artisan documents:sync-types` on deploy.
- Configure `config('documentable.authorization.resolver')`. In production, leaving it unset throws
  `InsecureConfiguration`. Use `MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer` or implement
  `AuthorizesDocumentAccess` with explicit methods (`canInitiateUpload`, `canFinalize`, `canAttach`,
  `canView`, `canDelete`, `canViewMany`).
- Call `Relation::enforceMorphMap()` and pass the morph alias, not class names, as `documentable_type`.
  Unmapped types are rejected unless listed in `security.allowed_documentable_types`.
- Define a `RateLimiter::for('documents', ...)`. Routes mount under `config('documentable.middleware')`,
  default `['api']` without session authentication.

### Operational commands

- `documents:sync-types [--prune]`, `documents:doctor`, `documents:list`, `documents:verify [--repair]`.
- `documents:clean-orphaned [--hours=N] [--storage-files]` is the reaper (auto-scheduled).
- `documents:prune-versions [--dry-run]` enforces version retention policies.
- `documents:configure-bucket-cors {disk} --origin=...` and `documents:configure-bucket-lifecycle {disk}`.

### Pitfalls

- Set `'throw' => true` on the documents disk. A failed delete returns `false` on quiet disks, keeping
  orphaned `StorageFile` records.
- Document events fire after the outermost database commit. Queued listeners must implement
  `ShouldQueueAfterCommit`; listeners cannot abort the transaction by throwing.
- Direct file reads bypass access logging and verification. Use `readContents()` or `readStream()`.
- Use `MadeByClowd\Documentable\Documentable::fake()` in tests to swap storage with test fakes.
- Run `php artisan boost:update` after upgrading; consuming apps retain snapshots of these files.

See the `laravel-documentable` Agent Skill (installed alongside this guideline) for upload transports,
versioning semantics, the security checklist and troubleshooting.
