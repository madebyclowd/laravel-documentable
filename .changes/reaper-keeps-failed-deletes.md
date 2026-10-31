---
bump: patch
type: Fixed
---

An expired single-PUT upload session now deletes its object before it is marked expired, so a failed delete leaves the session open for the next run instead of an expired row that still has an object.
