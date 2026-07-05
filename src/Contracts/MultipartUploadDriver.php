<?php

namespace MadeByClowd\Documentable\Contracts;

/**
 * Isolates provider-specific multipart upload API calls out of DocumentService.
 * Resolved by the disk's Flysystem driver name (config('documentable.multipart.drivers')),
 * so a non-S3-shaped backend (GCS resumable uploads, Azure block blobs) can be added later
 * as an additive driver, not a DocumentService rewrite.
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
     * @param  array<int, array{PartNumber: int, ETag: string}>  $parts
     */
    public function complete(string $disk, string $path, string $uploadId, array $parts): void;

    public function abort(string $disk, string $path, string $uploadId): void;
}
