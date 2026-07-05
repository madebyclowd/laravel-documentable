# Laravel Documentable

[![Latest Version on Packagist](https://img.shields.io/packagist/v/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![Total Downloads](https://img.shields.io/packagist/dt/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

Customizable, S3-compatible-first document storage for Laravel — content-addressable dedup,
composable versioning, multipart uploads, and orphan cleanup, without forcing you to adopt one
opinionated storage backend or admin UI.

**Status: under active development.** See `docs/implementations/` for the phased build plan.
Phase 0 (this scaffold) is the only phase implemented so far.

---

## Why

Most Laravel apps end up hand-rolling document/attachment handling per project. This package
generalizes that into a reusable, non-project-specific package:

- Dedup by content hash, not by convention.
- Versioning that composes with "multiple documents of the same type per model" instead of
  conflicting with it.
- Multipart uploads that don't skip validation, and an ETag strategy that works whether or not you
  control your bucket's CORS config (S3, R2, MinIO, SaaS-managed buckets all supported).
- Orphan/abandoned-upload cleanup as an explicit lifecycle, not an inferred one.
- Every app-specific decision point (authorization, AV scanning, dedup scope, storage path layout,
  multipart backend) is a contract you bind, not a fork you maintain.

Full design rationale: `docs/audits/` (what was wrong with prior hand-rolled implementations) and
`docs/plans/package-plan.md` (what this package does instead).

---

## Installation

```bash
composer require madebyclowd/laravel-documentable
```

Installation wizard and full usage docs land in later phases (see `docs/implementations/phase-7-release-and-docs.md`).

---

## Development status

This repository is being built in phases — see `docs/implementations/README.md` for the full plan:

- [x] Phase 0 — package scaffold (composer.json, service provider, CI, config shell)
- [ ] Phase 1 — core schema & direct upload
- [ ] Phase 2 — versioning & multi-document groups
- [ ] Phase 3 — multipart upload
- [ ] Phase 4 — lifecycle & orphan cleanup
- [ ] Phase 5 — pluggable contracts & security
- [ ] Phase 6 — events & observability
- [ ] Phase 7 — routes, install command, docs, release

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
