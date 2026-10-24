---
bump: patch
type: Security
---

A scanner result of `ScanResult::Error` (no verdict, for example the scanner service is down) used to be treated as clean, so an outage silently turned scanning off. It now rejects the upload and removes the object. `documentable.security.scan_error_policy` set to `allow` restores the old availability-first behavior and logs a warning per unscanned file.
