---
name: laravel-documentable-upgrade-v3
description: "Use when upgrading madebyclowd/laravel-documentable to 3.x in Laravel applications. Covers migration order, static scanner grep patterns, data inspection and repair, database migrations, configuration updates, and verification."
license: MIT
metadata:
  author: madebyclowd
---

# Upgrading to laravel-documentable 3.x

This upgrade skill provides a mechanical procedure to migrate applications from `madebyclowd/laravel-documentable` 2.x to 3.x.

This upgrade skill is a one-time migration aid and will be removed in v3.1.

## Migration Order

Execute migration steps in this exact order:

1. **Update package dependencies**
   Run `composer require madebyclowd/laravel-documentable:^3.0`.
   Resolves dependencies for the 3.0 release. See UPGRADE.md heading [Requirements](UPGRADE.md#requirements) and [Migration order](UPGRADE.md#migration-order).

2. **Scan code for removed or renamed v2 APIs**
   Run `php artisan documents:upgrade-check --code`.
   Flags removed methods, sealed classes, and outdated configuration keys before executing code.

3. **Inspect database constraints preflight**
   Run `php artisan documents:upgrade-check`.
   Inspects existing database records for duplicate latest markers or non-unique storage files that would block schema migrations.

4. **Repair data anomalies**
   Run `php artisan documents:repair --dry-run` then `php artisan documents:repair --apply`.
   Demotes duplicate latest markers and deduplicates storage file records. See UPGRADE.md heading [Deterministic single-slot marker and unique dedup index](UPGRADE.md#deterministic-single-slot-marker-and-unique-dedup-index).

5. **Run schema migrations**
   Run `php artisan migrate`.
   Applies new tables, indexes, and session tracking columns. See UPGRADE.md heading [Lock behavior and rollback](UPGRADE.md#lock-behavior-and-rollback).

6. **Synchronize document types**
   Run `php artisan documents:sync-types`.
   Upserts type definitions and retention policies into the database.

7. **Update AI assistant guidelines**
   Run `php artisan boost:update`.
   Refreshes local Boost guidelines and skills to version 3.

## Code Scanner Rules and Grep Patterns

Address each rule detected by `php artisan documents:upgrade-check --code`:

### documentservice-subclass
- Rule ID: `documentservice-subclass`
- Matching heading in UPGRADE.md: [DocumentService internals sealed](UPGRADE.md#documentservice-internals-sealed)
- Pattern: `extends\s+[A-Za-z0-9_\\]*DocumentService\b`
- Fix: Replace inheritance with service composition using public methods (`attachStorageFile`, `attach`, `readContents`, `readStream`).

### removed-upload-api
- Rule ID: `removed-upload-api`
- Matching heading in UPGRADE.md: [Removed upload API](UPGRADE.md#removed-upload-api)
- Pattern: `\buploadDetached\s*\(|\breassociateDocument\s*\(|^(?=.*ocument).*->commit\s*\(|\bDocumentStatus::Pending\b|status.*['"]pending['"]|['"]pending['"].*status`
- Fix: Use `stageUpload()` and `attach($session, $owner)`. Handle `DocumentStatus::Processing` instead of pending status.

### old-authorizer
- Rule ID: `old-authorizer`
- Matching heading in UPGRADE.md: [Authorization contract rewritten](UPGRADE.md#authorization-contract-rewritten)
- Pattern: `\bPermissiveDocumentAuthorizer\b|\bcanUpload\s*\(`
- Fix: Implement explicit authorizer methods (`canInitiateUpload`, `canFinalize`, `canAttach`, `canView`, `canDelete`, `canViewMany`) or configure `GateDocumentAuthorizer`.

### native-checksum
- Rule ID: `native-checksum`
- Matching heading in UPGRADE.md: [Removed native checksum](UPGRADE.md#removed-native-checksum)
- Pattern: `\buse_native_checksum\b|\bretrieveChecksum\b`
- Fix: Remove `use_native_checksum` from `config/documentable.php`. Verification runs automatically through the verification pipeline.

### renamed-config
- Rule ID: `renamed-config`
- Matching heading in UPGRADE.md: [Renamed config keys](UPGRADE.md#renamed-config-keys)
- Pattern: `\bmax_versions_to_keep\b|\bpending_ttl_hours\b|\ballow_guest_user_id\b|\benforce_path_prefix\b`
- Fix: Rename `max_versions_to_keep` to `versions_kept`. Move `lifecycle.pending_ttl_hours` to `multipart.session_ttl_hours`. Remove `allow_guest_user_id` and `enforce_path_prefix`.

### direct-storage-read
- Rule ID: `direct-storage-read`
- Matching heading in UPGRADE.md: [Direct storage reads](UPGRADE.md#direct-storage-reads)
- Pattern: `Storage::(?:disk\(.*?\)->)?(?:get|readStream|read)\s*\(`
- Fix: Replace direct disk access with `DocumentService::readContents()` or `DocumentService::readStream()`.

### document-type-query
- Rule ID: `document-type-query`
- Matching heading in UPGRADE.md: [Document type queries](UPGRADE.md#document-type-queries)
- Pattern: `whereHas\(\s*['"]documentType['"]`
- Fix: Use `Document::ofType($code)` query scope or `withDocumentOfType($code)` on owner models.

### multipart-driver-contract
- Rule ID: `multipart-driver-contract`
- Matching heading in UPGRADE.md: [MultipartUploadDriver contract](UPGRADE.md#multipartuploaddriver-contract)
- Pattern: `\bimplements\s+[^;{]*\bMultipartUploadDriver\b`
- Fix: Update custom driver methods to accept `UploadSession $session` rather than individual string arguments.

### fake-driver-moved
- Rule ID: `fake-driver-moved`
- Matching heading in UPGRADE.md: [Testing fake driver moved](UPGRADE.md#testing-fake-driver-moved)
- Pattern: `Tests\\Fixtures\\FakeMultipartUploadDriver`
- Fix: Use `MadeByClowd\Documentable\Documentable::fake()` instead of the legacy test fixture.

### package-migration-diverges
- Rule ID: `package-migration-diverges`
- Matching heading in UPGRADE.md: [Package migrations](UPGRADE.md#package-migrations)
- Detection: Compares published migrations in `database/migrations/` against package sources.
- Fix: Re-publish with `php artisan vendor:publish --tag=documentable-migrations --force` or confirm custom divergence.

## Additional Breaking Changes

- **Automatic promotion on delete-latest**: Deleting the latest version promotes the previous version. Set `on_delete_latest => 'none'` on document types to retain v2 behavior. See UPGRADE.md heading [Delete-latest promotes by default](UPGRADE.md#delete-latest-promotes).
- **Default deduplication scoping**: Deduplication is scoped by type and tenant (`TypeAndTenantScope`). Configure `GlobalDedupScope` only if cross-tenant deduplication is intended. See UPGRADE.md heading [Deduplication Scoping Redesign & Proof-of-Possession Handshake](UPGRADE.md#deduplication-scoping-redesign--proof-of-possession-handshake).
- **URL options**: `DocumentService::getUrl()` accepts a `MadeByClowd\Documentable\ValueObjects\UrlOptions` object. See UPGRADE.md heading [URL options and download routes](UPGRADE.md#url-options-and-download-routes).
- **Typed owner keys**: Configure `config('documentable.key_type')` when all models share a key type (`int`, `uuid`, `ulid`, `string`). See UPGRADE.md heading [Typed owner key](UPGRADE.md#typed-owner-key).

## Verify

Run the verification suite to confirm migration success:

1. Code scanner passes cleanly:
   ```bash
   php artisan documents:upgrade-check --code
   ```
   Must exit with status code 0.

2. Data integrity preflight passes cleanly:
   ```bash
   php artisan documents:upgrade-check
   ```
   Must exit with status code 0.

3. Storage connectivity and permissions verify cleanly:
   ```bash
   php artisan documents:doctor
   ```
   Must report all checks passing.

4. Application test suite passes:
   ```bash
   php artisan test
   ```
   All application tests must be green.
