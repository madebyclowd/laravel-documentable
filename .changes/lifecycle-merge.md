---
bump: patch
type: Fixed
---

`documents:configure-bucket-lifecycle` keeps the bucket's other lifecycle rules instead of replacing them all, and adds an expiration rule for the doctor's scratch prefix.
