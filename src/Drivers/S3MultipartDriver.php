<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Drivers;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\Presigner;
use MadeByClowd\Documentable\Services\S3ClientDetector;
use MadeByClowd\Documentable\Services\S3ExceptionMapper;
use MadeByClowd\Documentable\Services\ServerSideEncryption;

/**
 * Covers S3, R2, MinIO, DigitalOcean Spaces, and any other S3-API-compatible
 * backend registered under Flysystem's 's3' driver — the overwhelming majority
 * case. It is the only driver the package ships.
 */
class S3MultipartDriver implements MultipartUploadDriver
{
    public function __construct(private readonly Presigner $presigner) {}

    public function create(string $disk, string $path, string $filename): array
    {
        $params = [
            'Bucket' => $this->bucket($disk),
            'Key' => $path,
        ];

        if (config('documentable.multipart.object_metadata', false)) {
            $params['Metadata'] = [
                'original_filename' => rawurlencode($filename),
            ];
        }

        if ($contentType = config('documentable.multipart.content_type')) {
            $params['ContentType'] = $contentType;
        }

        $params = array_merge($params, $this->sseOptions($disk));

        $result = $this->client($disk)->createMultipartUpload($params);

        return ['upload_id' => $result['UploadId']];
    }

    public function presignPartUpload(UploadSession $session, int $partNumber): string
    {
        $ttl = now()->modify((string) config('documentable.multipart.part_upload_url_ttl', '+1 hour'));

        return $this->presigner->part($session->disk, $session->path, (string) $session->upload_id, $partNumber, $ttl);
    }

    public function listParts(UploadSession $session): array
    {
        $client = $this->client($session->disk);
        $bucket = $this->bucket($session->disk);
        $uploadId = (string) $session->upload_id;

        $parts = [];
        $nextPartMarker = null;

        try {
            do {
                $params = [
                    'Bucket' => $bucket,
                    'Key' => $session->path,
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
        } catch (S3Exception $e) {
            throw S3ExceptionMapper::mapMultipart($e, $session->path, $uploadId);
        }

        return $parts;
    }

    public function complete(UploadSession $session, array $parts): void
    {
        $uploadId = (string) $session->upload_id;

        try {
            $this->client($session->disk)->completeMultipartUpload([
                'Bucket' => $this->bucket($session->disk),
                'Key' => $session->path,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => array_values($parts)],
            ]);
        } catch (S3Exception $e) {
            throw S3ExceptionMapper::mapMultipart($e, $session->path, $uploadId);
        }
    }

    public function abort(UploadSession $session): void
    {
        $uploadId = (string) $session->upload_id;

        try {
            $this->client($session->disk)->abortMultipartUpload([
                'Bucket' => $this->bucket($session->disk),
                'Key' => $session->path,
                'UploadId' => $uploadId,
            ]);
        } catch (S3Exception $e) {
            throw S3ExceptionMapper::mapMultipart($e, $session->path, $uploadId);
        }
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
        return S3ClientDetector::bucket($disk);
    }

    /**
     * Explicit SSE, passed through rather than relying on the bucket's
     * default encryption setting.
     */
    protected function sseOptions(string $disk): array
    {
        return ServerSideEncryption::options($disk);
    }
}
