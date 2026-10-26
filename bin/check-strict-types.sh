#!/usr/bin/env bash
# Lists PHP files changed since a base ref that lack `declare(strict_types=1);`.
# New and edited files must carry it; untouched files are not rewritten just for this.
# Usage: bin/check-strict-types.sh [base-ref]   (default: origin/3.x)
set -euo pipefail

base="${1:-origin/3.x}"
missing=0

while IFS= read -r file; do
    [ -f "$file" ] || continue
    if ! grep -qE '^declare\(strict_types=1\);' "$file"; then
        echo "::error file=${file}::missing declare(strict_types=1);"
        missing=1
    fi
done < <(git diff --name-only --diff-filter=AM "$(git merge-base "$base" HEAD)" HEAD -- 'src/*.php' 'tests/*.php' 'routes/*.php' 'config/*.php' 'database/*.php')

exit "$missing"
