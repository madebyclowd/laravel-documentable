# Changelog

All notable changes to `laravel-documentable` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/).

New entries from this point forward are generated from `.changes/*.md` changesets — see
[.changes/README.md](.changes/README.md) — not edited here by hand. The three entries below are a
retroactive seed summarizing the pre-changesets manual releases.

## [3.0.0-alpha.2] - 2026-10-26

### Changed
- `DocumentService` is now a facade over focused internal classes (`DirectUploadService`, `MultipartUploadService`, `ObjectVerifier`, `DocumentAttacher`, `UrlSigner`, `DocumentPurger`, `AccessLogger`). Its public methods keep their names and signatures. Its protected members and constructor are gone, so a subclass that overrode or called them must switch to the public methods, the package events, or the contracts. Resolve the service from the container instead of constructing it.
- `documents:clean-orphaned` now expires upload sessions past their expiry instead of sweeping `pending` documents and `multipart_uploads`. Expiring a session aborts an open multipart upload on the provider, or deletes the object it holds, unless the session was attached in the meantime. `--hours` expires any open session older than that.
- Every upload transport now runs on an upload session, and `DocumentService::attach($session, $owner, new AttachOptions(...))` is the only way a session becomes a document. Presigned finalize and multipart complete take a `session_id` instead of a `path` and an `upload_id`, so a client can no longer point them at another object in your bucket. Only the user a session was started under can drive it (403 otherwise), a `document_type_id` that disagrees with the session's type is a 422, and an expired session refuses to complete. A failed check now deletes the object and aborts the session. The owner fields are optional on `POST /documents`, `presigned/finalize` and `multipart/complete`; without them the response is the `uploaded` session, to attach later with the new `POST /documents/attach`. `document_group_id` must name a group the owner already holds. Service signatures changed: `initiatePresignedUpload` replaces `createPresignedUpload`, and `initiateMultipartUpload`, `generatePartUploadUrl`, `listPartsForSession`, `completeMultipartUpload` and `finalizeDirectUpload` take sessions.

### Removed
- Removes `DocumentService::uploadDetached()`, `reassociateDocument()` and `Document::commit()`, the `pending` document status and scope, the `lifecycle.pending_ttl_hours` and `security.enforce_path_prefix` options, `POST /documents/detached`, the `GET /documents/multipart/status` fields `path` and `upload_id`, the `DocumentReassociated` event, and the `MultipartUpload` model and `multipart_uploads` table. Replace them with `stageUpload()` (or `POST /documents` without owner fields) followed by `attach()`. Document statuses are now `committed`, `processing` and `rejected` (`DocumentStatus`). The migration that drops `multipart_uploads` refuses to run while an unexpired session is open; set `DOCUMENTABLE_FORCE_DRAIN=true` to abort those provider uploads and drop the table. Open `pending` documents are converted to `uploaded` sessions; expired ones are deleted.

### Added
- Adds the `upload_sessions` table, the `UploadSession` model, the `UploadSessionStatus` and `UploadKind` enums, `UploadSessionData` and `AttachOptions`. A session is the server-side record of one upload in progress: its ULID is the only handle a client holds, and the storage path and provider upload id stay on the server. Publish and run the new migrations with `php artisan migrate`.

## [3.0.0-alpha.1] - 2026-10-26

### Changed
- Raises the minimum supported versions to PHP 8.3 and Laravel 12, and requires `ext-fileinfo`. PHP 8.2 and Laravel 11 are no longer supported; upgrade PHP or Laravel before updating to 3.x.

### Fixed
- Completing a multipart upload now always sends the parts to S3 as a plain list, even when the caller keyed them by part number. Previously a keyed array produced a request S3 could not read.

### Added
- The package now validates `config/documentable.php` when the app boots. A bad value (an unknown disk, a class that does not implement its contract, a scheduler frequency that does not exist, an out-of-range number) throws `InvalidDocumentableConfig` naming the key and the fix, instead of failing at the first upload. `php artisan about` shows the resolved disk, ETag strategy and reaper frequency.

## [2.1.3] - 2026-10-24

### Changed
- The Laravel Boost distribution is refreshed. The guideline is renamed from `core.blade.php` to `laravel-documentable.blade.php` and rewritten as a short orientation (conventions, commands, pitfalls) that points at the skill, matching `laravel-auto-sequence`. The skill gains trigger-style `description` wording, `license` and `metadata.author`, and its stale statements are corrected: a rejected presigned finalize no longer deletes the object, a body `user_id` is no longer the multipart owner fallback, the authorizer and `null` owner advice is fixed, and event timing, `purge()` failures, scanner errors, `throw => true` and the unsafe `use_native_checksum` are covered. Run `php artisan boost:update` in each consuming app to pick it up.
- The document events (`DocumentUploaded`, `DocumentDeleted`, `DocumentPurged`, `DocumentReassociated`, `DocumentVersionSuperseded`, `MultipartUploadInitiated`, `MultipartUploadAborted`) now implement `ShouldDispatchAfterCommit`. They fire immediately outside a transaction and after the outermost commit inside one; a rolled-back transaction drops them. This is a behavior change: a listener can no longer abort the surrounding transaction by throwing, and one that relied on running inside it now runs after it. `documents:sync-types` now reports the types it created and updated, lists types that exist only in the database, and `php artisan about` shows how config and the table compare. Shipped comments no longer point at internal planning documents (a PHPUnit test and a CI step enforce it), and the README documents event timing, the `throw => true` disk setting, the deploy steps, and why `use_native_checksum` should stay off.

### Fixed
- `$owner->documents` and its eager, `whereHas` and `withCount` forms failed on PostgreSQL for an owner with an integer primary key (`operator does not exist: character varying = integer`), because `documents.documentable_id` is a string column. `documents()` now returns a relation that sends the owner key as a string, so the `idAsString` accessors and `$keyType = 'string'` workarounds can be removed. Documents made through the relation also store the key as a string. SQLite and MySQL behave as before.
- Starting a multipart upload on Cloudflare R2 failed on PostgreSQL (and on MySQL in strict mode) with `value too long for type character varying(255)`: R2 upload ids are 343 characters. Two new migrations widen `multipart_uploads.path`, `multipart_uploads.upload_id` and `storage_files.path` to `text`, replace the `(path, upload_id, user_id)` index with one on `user_id`, and skip anything already widened, so run `php artisan migrate`. Session lookup is now case-sensitive on every engine. CI runs the MySQL and PostgreSQL legs on Laravel 11 and 12 as well.
- `reassociateDocument()` now resolves the slot like an upload: moving a document into a single-slot type its new owner already holds makes it the next version (or replaces the old one for a type without versioning) and dispatches `DocumentVersionSuperseded`, instead of leaving two `is_latest` rows. `purge()` now returns `false`, logs an error and keeps the `StorageFile` row when the blob cannot be deleted, and `documents:clean-orphaned --storage-files` retries those. Both are behavior changes: a caller that ignored the old `true` after a failed delete will now see `false`. A multipart session can no longer be completed, listed, presigned or aborted under a different document type than it was started with (422). The reaper no longer stops at an upload the provider has already dropped (`NoSuchUpload`), logs and continues past any other per-session failure, and exits non-zero if one failed. `documents:sync-types` warns about disks with `throw => false`.

### Security
- The multipart follow-up endpoints (`part-url`, `parts`, `status`, `abort`) now call `AuthorizesDocumentAccess::canUpload()` for the session's document type, as `initiate` and `complete` already did, and `GET /documents/types` lists only the types the authorizer allows. A request-body `user_id` is no longer accepted as the multipart session owner for unauthenticated callers unless `documentable.security.allow_guest_user_id` is `true`; guest or device flows that relied on it must set that key. `GET /documents/{document}/url` validates its query: `disposition` is `inline` or `attachment`, `expires_in_minutes` is 1 to `documentable.url.max_ttl_minutes` (default 1440), and an expired `pending` document returns 404. Booting in production with the routes mounted and the permissive authorizer now logs a warning and appears in `php artisan about`.
- A failed `POST /documents/presigned/finalize` (`finalizeDirectUpload()`) no longer deletes the object it was pointed at. Because `path` is supplied by the client, a wrong `expected_hash` or a size/mime rejection used to delete any key a caller named, and finalizing the same path twice deleted the live blob. Rejected uploads now stay in the bucket, so add a lifecycle expiration rule for your upload prefixes. Finalizing a path that already backs a stored file is refused, and `path` must be a relative key (no leading `/`, no `..`, no backslash, at most 1,024 characters). New opt-in `documentable.security.enforce_path_prefix` restricts a finalize to the document type's `path_prefix`. A caller who passes `canUpload` can still adopt an existing bucket key that no stored file uses, until the v3 upload sessions land, so keep the bucket private to this package.
- A scanner result of `ScanResult::Error` (no verdict, for example the scanner service is down) used to be treated as clean, so an outage silently turned scanning off. It now rejects the upload and removes the object. `documentable.security.scan_error_policy` set to `allow` restores the old availability-first behavior and logs a warning per unscanned file.

## [2.1.2] - 2026-08-20

### Fixed
- `S3MultipartDriver::presignPartUpload()` now respects the disk's `temporary_url`
  config, matching `getUrl()` and `createPresignedUpload()`. Previously it signed
  directly against the raw SDK client and returned the internal endpoint
  unconditionally, so multipart uploads (files over `multipart.threshold_bytes`,
  10MB by default) silently bypassed `temporary_url` while small-file uploads
  respected it — breaking part-upload URLs for any setup relying on
  `temporary_url` to expose a different public endpoint than the internal one
  (e.g. MinIO/S3-compatible storage behind a reverse proxy).

## [2.1.1] - 2026-08-20

### Fixed
- Added `.gitattributes` to normalize line endings to LF for tracked text files, fixing spurious
  `vendor/bin/pint --test` failures for contributors whose local git config checks files out with
  CRLF (e.g. `core.autocrlf=true` on Windows/WSL) even though CI itself was never affected.

## [2.1.0] - 2026-08-20

### Added
- `GET /documents/types` read-only document-type catalog endpoint.
- `GET /documents/multipart/parts` — list uploaded parts for an in-progress multipart session, a
  prerequisite for real client-side resume.
- `GET /documents/multipart/status` — distinguishes an expired session from a transient error.
- `documents:configure-bucket-cors` command, sibling to the lifecycle command.
- `--shape`/`--etag-strategy`/`--types` install flags, plus a loud warning on the unsafe
  `--no-interaction` default.

### Changed
- `getUrl()` on a disk without `temporaryUrl()` support now throws an actionable
  `DiskDoesNotSupportTemporaryUrlsException` instead of a raw Flysystem error.
- Documented and scaffolded the nullable-`$documentable` `canUpload()` call sites.
- Verified and documented the cross-provider part-retry/idempotency guarantee on
  `MultipartUploadDriver` (AWS S3, R2, MinIO, Spaces).
- README worked example for morph-alias vs. FQCN `documentable_type` payloads.

Every item in this release is additive or docs-only — no existing endpoint's request/response shape
or shipped config default's behavior changed. No consumer of `2.0.0` breaks by upgrading.

## [2.0.0] - 2026-07-06

### Changed
- **Breaking**: `documentable_type` now rejects any value that isn't morph-mapped or explicitly
  allowlisted — a previously-accepted raw Eloquent FQCN payload now fails validation. This is what
  makes the release major rather than minor, even though every other item below is additive on its
  own.
- Upload endpoint responses (`store()`/`finalize()`/`complete()`) now eager-load and return the
  `storageFile` relation instead of the bare `Document` model.

### Added
- `documents:make-authorizer` scaffolding command, wired into the install flow.
- `config('documentable.middleware')` + an install-flow prompt for monolith vs. API route mounting.
- `GET /documents` — owner-scoped, grouped-by-slot document listing endpoint.
- `documents:attach-model` command — adds the `Documentable` trait + import to a model automatically.
- Laravel Boost AI-skill auto-discovery integrated correctly (guideline/skill files now land in
  `.claude/skills` as well as `.github/skills`/`.ai/skills`).

## [1.0.0] - 2026-07-06

Initial release.

### Added
- Package scaffold: composer identity, `DocumentableServiceProvider`, CI, empty config.
- Core schema (`documents`/`document_types`/`storage_files`) and models, `Documentable` trait,
  direct-upload path with content-addressable dedup and purge.
- `document_group_id` versioning, portable `latest_marker` uniqueness, version-history query
  surface, retention pruning.
- Multipart upload (initiate/part-url/complete/abort), session ownership, configurable ETag
  strategy (`client` / `server-authoritative`), shared validation pipeline, size-based transport
  threshold.
- Explicit `status`/`expires_at` lifecycle, generic orphan-reaper command, S3 lifecycle-rule
  guidance.
- Pluggable authorization/AV-scan/dedup-scope/path-generation contracts, header-injection fix, SSE
  config, presigned URL expiry config, throttling.
- Domain events, optional access-log table, configurable actor tracking, optional S3-checksum fast
  path.
- Routes, requests, install command, Boost AI skill doc, README, multi-DB CI matrix, tag/release
  workflow.

[2.1.0]: https://github.com/madebyclowd/laravel-documentable/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/madebyclowd/laravel-documentable/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/madebyclowd/laravel-documentable/releases/tag/v1.0.0

[2.1.1]: https://github.com/madebyclowd/laravel-documentable/compare/v2.1.0...v2.1.1

[2.1.2]: https://github.com/madebyclowd/laravel-documentable/compare/v2.1.1...v2.1.2

[2.1.3]: https://github.com/madebyclowd/laravel-documentable/compare/v2.1.2...v2.1.3

[3.0.0-alpha.1]: https://github.com/madebyclowd/laravel-documentable/compare/v2.1.3...v3.0.0-alpha.1

[3.0.0-alpha.2]: https://github.com/madebyclowd/laravel-documentable/compare/v3.0.0-alpha.1...v3.0.0-alpha.2
