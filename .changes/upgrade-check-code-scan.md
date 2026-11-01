---
bump: patch
type: Changed
---

`documents:upgrade-check --code` reports fewer false positives: migrations are skipped (except the published-copy check), block comments are ignored, and the string `'pending'` status match needs a document mention on the line. A rule can be skipped with `--ignore=<rule-id>`.
