<?php

namespace MadeByClowd\Documentable\Contracts;

/**
 * Isolates provider-specific multipart upload API calls out of DocumentService.
 * Resolved by the disk's Flysystem driver name (config('documentable.multipart.drivers')),
 * so a non-S3-shaped backend (GCS resumable uploads, Azure block blobs) can be added later
 * as an additive driver, not a DocumentService rewrite.
 *
 * Part-retry contract: implementations targeting an S3-API-compatible backend (S3, R2,
 * MinIO, Spaces) must guarantee that re-uploading a given PartNumber any number of times
 * before complete()/abort() is safe and last-write-wins, with no error and no requirement
 * to abort the prior attempt first — a client that can't tell whether an ambiguous PUT
 * failure landed may always retry that part number without first calling listParts(). This
 * is confirmed AWS S3 API behavior (docs.aws.amazon.com/AmazonS3/latest/API/API_UploadPart.html)
 * and is directly documented the same way by Cloudflare R2; MinIO and DigitalOcean Spaces
 * commit to it only by explicit reference to the AWS spec rather than restating it in their
 * own docs. One provider-specific edge case: R2's own docs note that if the *retry itself*
 * fails mid-flight, that part number can be left with no stored data rather than falling
 * back to the prior copy — the guarantee covers a successfully-completed re-upload, not a
 * retry that itself errors. A driver for a non-S3-shaped backend (a future GCS/Azure driver)
 * must document its own retry semantics explicitly if they differ, rather than silently
 * inheriting this guarantee.
 */
interface MultipartUploadDriver
{
    /**
     * @return array{upload_id: string}
     */
    public function create(string $disk, string $path, string $filename): array;

    public function presignPartUpload(string $disk, string $path, string $uploadId, int $partNumber): string;

    /**
     * @return array<int, array{PartNumber: int, ETag: string}>
     */
    public function listParts(string $disk, string $path, string $uploadId): array;

    /**
     * Assemble the uploaded parts into the final object. Re-uploading the same PartNumber
     * any number of times before this call is safe (last-write-wins, per the interface-level
     * part-retry contract) — a client that can't tell whether an ambiguous PUT failure
     * landed may always retry that part number without first checking listParts().
     *
     * @param  array<int, array{PartNumber: int, ETag: string}>  $parts
     */
    public function complete(string $disk, string $path, string $uploadId, array $parts): void;

    public function abort(string $disk, string $path, string $uploadId): void;

    /**
     * Retrieve a provider-computed full-object checksum for the assembled object, if the
     * backend supports it and config('documentable.multipart.use_native_checksum') is
     * enabled — an optional fast path that avoids a full re-download-and-hash. Return null
     * when unsupported/unavailable; the caller always falls back to the full-stream-hash
     * check in that case, so this is never the only integrity path.
     *
     * @return string|null hex-encoded sha256, same format as hash('sha256', ...)
     */
    public function retrieveChecksum(string $disk, string $path): ?string;
}
