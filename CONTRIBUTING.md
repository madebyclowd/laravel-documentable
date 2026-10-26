# Contributing

## Branches and releases

| Branch | Line | Versions |
| --- | --- | --- |
| `main` | 2.x, maintenance | `2.1.x` |
| `3.x` | next major | `3.0.0-alpha.N`, then `-rc.N`, then `3.0.0` |

- Open pull requests against the line they belong to. A fix needed on both lines lands on `main` first
  and is merged forward into `3.x`.
- Every user-facing change adds a changeset in `.changes/` (see `.changes/README.md`).
- On `3.x`, `.github/workflows/version.yml` opens a `chore(release): v3.0.0-alpha.N` pull request into `3.x`.
  The prerelease channel comes from the repository variable `PRERELEASE_CHANNEL` (`alpha` when unset).
  Set it to `rc` for release candidates and to `stable` to cut `3.0.0`.
- A tag with a hyphen is published as a GitHub prerelease, and Composer does not install it for a
  `^3.0` constraint unless the project allows that stability.

## Before you push

```bash
composer test      # PHPUnit; DB_CONNECTION=mysql or pgsql runs the same suite on that engine
composer format    # Pint, check only; run vendor/bin/pint to fix
composer analyse   # PHPStan level 8 with the 1G memory limit CI uses
```

Write the failing test first. Coverage is measured on the newest PHP and Laravel leg only, so measure it
locally with `php -d pcov.enabled=1 vendor/bin/phpunit --coverage-text` before claiming it did not drop.

## Quality gates

- **PHPStan baseline only shrinks.** `phpstan-baseline.neon` is regenerated once at the start of 3.x.
  Never add an entry; fix the error. An entry that no longer matches fails the run, so remove it in the
  same change that fixes it.
- **`declare(strict_types=1);`** is required in every new or edited PHP file. `bin/check-strict-types.sh`
  lists the files that lack it and CI runs it on pull requests.
- **Shipped text stands alone.** Comments, config and docs that ship to Packagist must not point at
  internal planning documents. CI and `tests/Unit/ShippedTextTest.php` enforce it.
- **Backward compatibility** against the last 2.x release is reported on every pull request to `3.x`
  (report only until `3.0.0`).

## Public API

- `@api` marks a class or interface whose signatures follow semver. Apps may call, implement or extend
  it as documented.
- `@internal` marks everything else, including every `protected` member of a class not marked `@api`.
  It can change in any release.
- Configuration is read through `MadeByClowd\Documentable\Config\DocumentableConfig`, validated at boot.
  Add a new key there (with its validation and a row in `DocumentableConfigTest`) instead of calling
  `config()` at the use site.
