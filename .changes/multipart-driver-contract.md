---
bump: major
type: Changed
---

`MultipartUploadDriver` is now `@api` and takes the `UploadSession` for every call after `create()`. Provider errors map to `MultipartUploadNotFoundException` and the new `MultipartPartTooSmallException`. `MultipartDriverConformanceTest` lets a driver package prove it honors the contract, and `PartSizePlanner` plus a `part_size` field in the initiate response give clients the constant part size Cloudflare R2 requires.
