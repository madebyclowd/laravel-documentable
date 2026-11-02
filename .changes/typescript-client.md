---
bump: minor
type: Added
---

A dependency-free TypeScript upload client, published with `php artisan vendor:publish --tag=documentable-client`. `uploadFile()` hashes the file incrementally, answers the dedup handshake with a proof of possession, uploads by presigned PUT or multipart with parallel parts, retry, resume and abort, waits for a `processing` document, and accepts Wayfinder route functions.
