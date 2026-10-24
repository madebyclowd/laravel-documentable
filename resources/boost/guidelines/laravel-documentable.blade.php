## laravel-documentable

Customizable, S3-compatible-first document storage: content-addressable dedup, composable
versioning (`allows_multiple` × `requires_versioning`), presigned direct-PUT and multipart uploads,
orphan cleanup, and pluggable authorization/security/storage-path contracts.

### Core conventions

- Attach `MadeByClowd\Documentable\Traits\Documentable` to any Eloquent model to get a
  `documents()` `MorphMany` relation. `php artisan documents:attach-model {Model}` wires this in
  automatically instead of a manual 2-line edit.
- `DocumentType` rows are the catalog of upload "slots" (max size, allowed mimes, disk,
  `allows_multiple`, `requires_versioning`). Prefer code-first: define them in
  `config('documentable.types')` and run `php artisan documents:sync-types` — don't hand-build
  admin CRUD for this table.
- All uploads funnel through `MadeByClowd\Documentable\Services\DocumentService` — never write to
  `documents`/`storage_files` directly.
- Default `AuthorizesDocumentAccess` is **permissive** (allows everything) and
  `documentable_type` resolution **rejects** any type that isn't in `Relation::morphMap()` or
  `config('documentable.security.allowed_documentable_types')`. Run
  `php artisan documents:make-authorizer` before production use — see "Security" below.

### HTTP routes (optional)

`config('documentable.load_routes')` (default `true`) mounts `/documents` under
`config('documentable.middleware')` (default `['api']` — no session/auth,
`$request->user()` is `null`). `php artisan documents:install` asks whether the app is a
session-based monolith (sets `['web', 'auth']`) or a separate API — pass `--shape=`/
`--etag-strategy=`/`--types=` to skip the prompts in a scripted install (omitting `--shape` under
`--no-interaction` warns and defaults to `separate-api`, the one choice with a real security
consequence). `GET /documents/types` lists the type catalog; `GET /documents` lists an owner's
documents grouped by type/slot; `GET /documents/multipart/status` and `/multipart/parts` support
correct client-side resume after a dropped upload. The rest cover upload (direct-PUT and multipart)
and single-document url/delete. Disable `load_routes` and mount
`MadeByClowd\Documentable\Http\Controllers\*` yourself for full control. Bucket CORS for the
direct-PUT/multipart-part flows: `php artisan documents:configure-bucket-cors {disk} --origin=...`.

### Security

Before production use:

1. `Relation::enforceMorphMap()` (recommended) or set
   `config('documentable.security.allowed_documentable_types')` — an unmapped
   `documentable_type` is rejected by default, but only a morph map or explicit allowlist makes
   that meaningful. Send the map **alias**, not the FQCN, once a morph map is registered.
2. `php artisan documents:make-authorizer` to scaffold a real `AuthorizesDocumentAccess`
   implementation, then bind it via `config('documentable.authorization.resolver')`. Its
   `canUpload()` receives `$documentable = null` from `storeDetached()`/multipart `initiate()` (no
   owner yet) — handle that case explicitly or those calls silently 403 with no obvious cause.

@verbatim
<code-snippet name="Attach the trait and upload a file" lang="php">
use MadeByClowd\Documentable\Traits\Documentable;

class Invoice extends Model
{
    use Documentable;
}

$service = app(\MadeByClowd\Documentable\Services\DocumentService::class);
$document = $service->upload($request->file('file'), $type, $invoice);
</code-snippet>
@endverbatim

See the `laravel-documentable` Agent Skill (installed alongside this guideline) for the full
upload-transport, versioning, and operational-command reference.
