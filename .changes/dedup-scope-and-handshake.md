---
bump: major
type: Security
---

Close hash oracle vulnerability by redesigning deduplication scoping and introducing proof-of-possession handshake:
- Changed default deduplication scope resolver from `HashOnlyDedupScope` to `TypeAndTenantScope`. Reused storage files are now scoped deterministically by document type and tenant id (via the `HasDedupTenant` contract or `TypeAndTenantScope::resolveTenantUsing()`), preventing cross-tenant and cross-type hash collision claims.
- Added `GlobalDedupScope` (bare content hash) and `OwnerScopedDedupScope` for documented opt-in configurations.
- Added `storage_files.content_hash` to record the raw SHA-256 content hash while `file_hash` holds the scoped deduplication key; updated integrity verification to inspect `content_hash`.
- Added proof-of-possession challenge/response handshake endpoints (`POST /documents/handshake` and `POST /documents/handshake/answer`) backed by race-safe single-use `dedup_challenges` with attempt limiting (3 wrong answers dead limit) and reaper pruning.
- A handshake hit and miss return the same response shape; a hit needs the stored size, and the optional owner is used to resolve the scope. The answer re-checks `canInitiateUpload`, only the user a challenge was issued to can answer it, and the proof reads only the challenged ranges (one ranged GET on S3-compatible disks).
- An open upload session now keeps its stored file from being removed by the orphan sweep.
- Added reference range-proof implementation (`RangeProof`) with committed test vector fixtures.
