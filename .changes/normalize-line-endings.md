---
bump: patch
type: Fixed
---

Added `.gitattributes` to normalize line endings to LF for tracked text files, fixing spurious
`vendor/bin/pint --test` failures for contributors whose local git config checks files out with
CRLF (e.g. `core.autocrlf=true` on Windows/WSL) even though CI itself was never affected.
