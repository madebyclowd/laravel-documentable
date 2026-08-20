# Changelog

All notable changes to `laravel-documentable` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), and this project adheres to [Semantic Versioning](https://semver.org/).

New entries from this point forward are generated from `.changes/*.md` changesets — see
[.changes/README.md](.changes/README.md) — not edited here by hand. The three entries below are a
retroactive seed summarizing the pre-changesets manual releases.

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
