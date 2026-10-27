---
bump: major
type: Removed
---

`multipart.use_native_checksum` and `MultipartUploadDriver::retrieveChecksum()` are removed. An S3 multipart
checksum is a checksum of the part checksums, so it never equalled a file's SHA-256 and turning the option on
made a correct `expected_hash` delete the upload. The server now always verifies the object itself, streaming
it through the hash in constant memory (about four times faster than the previous 8 KiB read loop on a 1 GiB
object). A custom multipart driver must drop `retrieveChecksum()`, and a published config should drop the key.
