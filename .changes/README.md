# Changesets

This is how a PR declares what its change means for the next release —
instead of hand-editing `CHANGELOG.md` (which conflicts constantly when
multiple PRs are in flight) or relying on commit-message parsing.

## Adding one

For any user-facing change, copy `TEMPLATE.md` to a new file in this
directory named after your change (e.g. `fix-getUrl-driver-fallback.md` —
the filename doesn't matter beyond being unique and ending in `.md`), then
fill in the two frontmatter fields and a description:

```markdown
---
bump: patch
type: Fixed
---

`getUrl()` on a disk without `temporaryUrl()` support now throws an
actionable `DiskDoesNotSupportTemporaryUrlsException` instead of a raw
Flysystem error.
```

- **bump**: `patch`, `minor`, or `major` — same meaning as semver. If a PR
  ships multiple changesets, the highest bump across all of them wins.
- **type**: one of `Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`,
  `Security` — matches the [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
  categories already used in `CHANGELOG.md`.
- The body becomes the changelog bullet verbatim, so write it the way you'd
  want a user reading the changelog to read it.

Skip this for internal-only changes (tests, CI, docs, refactors with no
user-visible effect).

## What happens next

On every push to `main`, `.github/workflows/version.yml` checks for pending
changeset files. If there are any, it computes the next version, rewrites
`CHANGELOG.md`, deletes the consumed changeset files, and opens (or updates)
a `chore(release): vX.Y.Z` pull request with that diff. Merging that PR is
what actually cuts the release — the same workflow tags the merge commit,
which triggers `.github/workflows/release.yml` (SBOM + GitHub release), the
same as a manual `git tag && git push` always has.

No new runtime dependency is needed to consume changesets — the aggregator
is a plain PHP script (`bin/aggregate-changesets.php`), the same language as
the rest of this package.
