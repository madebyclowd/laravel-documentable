<?php

namespace MadeByClowd\Documentable\Drivers;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;

/**
 * Covers S3, R2, MinIO, DigitalOcean Spaces, and any other S3-API-compatible
 * backend registered under Flysystem's 's3' driver — the overwhelming majority
 * case. Ships as the only driver in phase 3.
 */
class S3MultipartDriver implements MultipartUploadDriver
{
    public function create(string $disk, string $path, string $filename): array
    {
        $result = $this->client($disk)->createMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $path,
            'ContentType' => 'application/octet-stream',
            'Metadata' => [
                'original_filename' => $filename,
            ],
        ]);

        return ['upload_id' => $result['UploadId']];
    }

    public function presignPartUpload(string $disk, string $path, string $uploadId, int $partNumber): string
    {
        $client = $this->client($disk);

        $command = $client->getCommand('UploadPart', [
            'Bucket' => $this->bucket($disk),
            'Key' => $path,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        $ttl = config('documentable.multipart.part_upload_url_ttl', '+1 hour');

        return (string) $client->createPresignedRequest($command, $ttl)->getUri();
    }

    public function listParts(string $disk, string $path, string $uploadId): array
    {
        $client = $this->client($disk);
        $bucket = $this->bucket($disk);

        $parts = [];
        $nextPartMarker = null;

        do {
            $params = [
                'Bucket' => $bucket,
                'Key' => $path,
                'UploadId' => $uploadId,
            ];

            if ($nextPartMarker) {
                $params['PartNumberMarker'] = $nextPartMarker;
            }

            $result = $client->listParts($params);

            foreach ($result['Parts'] ?? [] as $part) {
                $parts[] = [
                    'PartNumber' => $part['PartNumber'],
                    'ETag' => $part['ETag'],
                ];
            }

            $nextPartMarker = $result['NextPartNumberMarker'] ?? null;
        } while ($result['IsTruncated'] ?? false);

        return $parts;
    }

    public function complete(string $disk, string $path, string $uploadId, array $parts): void
    {
        $this->client($disk)->completeMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $path,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);
    }

    public function abort(string $disk, string $path, string $uploadId): void
    {
        $this->client($disk)->abortMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $path,
            'UploadId' => $uploadId,
        ]);
    }

    /**
     * @return S3Client
     */
    protected function client(string $disk)
    {
        return Storage::disk($disk)->getClient();
    }

    protected function bucket(string $disk): string
    {
        return (string) config("filesystems.disks.{$disk}.bucket");
    }
}
