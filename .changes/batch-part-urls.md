---
bump: minor
type: Removed
---

`POST /documents/multipart/part-url` is replaced by `POST /documents/multipart/part-urls`, which signs up to 100 parts in one request with one session load. Clients that asked for one URL per part must send `part_numbers[]` instead; see the upgrade guide.
