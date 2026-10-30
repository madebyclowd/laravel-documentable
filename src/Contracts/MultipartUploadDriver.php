<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Contracts;

use MadeByClowd\Documentable\Exceptions\MultipartPartTooSmallException;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Models\UploadSession;

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
 *
 * @api
 */
interface MultipartUploadDriver
{
    /**
     * @return array{upload_id: string}
     */
    public function create(string $disk, string $path, string $filename): array;

    /**
     * @throws MultipartUploadNotFoundException
     */
    public function presignPartUpload(UploadSession $session, int $partNumber): string;

    /**
     * @return array<int, array{PartNumber: int, ETag: string}>
     *
     * @throws MultipartUploadNotFoundException
     */
    public function listParts(UploadSession $session): array;

    /**
     * Assemble the uploaded parts into the final object. Re-uploading the same PartNumber
     * any number of times before this call is safe (last-write-wins, per the interface-level
     * part-retry contract) — a client that can't tell whether an ambiguous PUT failure
     * landed may always retry that part number without first checking listParts().
     *
     * @param  array<int, array{PartNumber: int, ETag: string}>  $parts
     *
     * @throws MultipartUploadNotFoundException
     * @throws MultipartPartTooSmallException
     */
    public function complete(UploadSession $session, array $parts): void;

    /**
     * Abort the provider-side upload and free its stored parts.
     *
     * @throws MultipartUploadNotFoundException when the provider has no such
     *                                          upload any more; the caller treats that as already aborted.
     */
    public function abort(UploadSession $session): void;
}
