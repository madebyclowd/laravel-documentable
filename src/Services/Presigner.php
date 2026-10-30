<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Exceptions\DiskDoesNotSupportTemporaryUrlsException;
use RuntimeException;

/**
 * Computes presigned URLs and required headers locally without performing network calls.
 *
 * @internal
 */
final class Presigner
{
    public function __construct(private readonly PresignClientFactory $factory) {}

    /**
     * Signs a time-limited download URL for a stored object.
     *
     * @throws DiskDoesNotSupportTemporaryUrlsException
     */
    public function download(string $disk, string $path, DateTimeInterface $expiry, string $contentDisposition): string
    {
        if (! S3ClientDetector::isS3($disk)) {
            try {
                return Storage::disk($disk)->temporaryUrl($path, $expiry, [
                    'ResponseContentDisposition' => $contentDisposition,
                ]);
            } catch (RuntimeException $e) {
                throw DiskDoesNotSupportTemporaryUrlsException::forDisk($disk, $e);
            }
        }

        $client = $this->factory->forDisk($disk);
        $bucket = S3ClientDetector::bucket($disk);

        $command = $client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key' => $path,
            'ResponseContentDisposition' => $contentDisposition,
        ]);

        return (string) $client->createPresignedRequest($command, $expiry)->getUri();
    }

    /**
     * Presigns a single PUT upload URL and extracts required request headers.
     *
     * @param  array<string, mixed>  $sseOptions
     * @return array{url: string, headers: array<string, mixed>}
     */
    public function upload(string $disk, string $path, DateTimeInterface $expiry, array $sseOptions = []): array
    {
        if (! S3ClientDetector::isS3($disk)) {
            $signed = Storage::disk($disk)->temporaryUploadUrl($path, $expiry, $sseOptions);

            return [
                'url' => (string) $signed['url'],
                'headers' => (array) ($signed['headers'] ?? []),
            ];
        }

        $client = $this->factory->forDisk($disk);
        $bucket = S3ClientDetector::bucket($disk);

        $command = $client->getCommand('PutObject', array_merge([
            'Bucket' => $bucket,
            'Key' => $path,
        ], $sseOptions));

        $request = $client->createPresignedRequest($command, $expiry);

        $headers = [];
        foreach ($request->getHeaders() as $key => $values) {
            if (strcasecmp($key, 'Host') !== 0) {
                $headers[$key] = $values[0] ?? '';
            }
        }

        return [
            'url' => (string) $request->getUri(),
            'headers' => $headers,
        ];
    }

    /**
     * Signs a time-limited multipart part upload URL.
     */
    public function part(string $disk, string $path, string $uploadId, int $partNumber, DateTimeInterface $expiry): string
    {
        $client = $this->factory->forDisk($disk);
        $bucket = S3ClientDetector::bucket($disk);

        $command = $client->getCommand('UploadPart', [
            'Bucket' => $bucket,
            'Key' => $path,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        return (string) $client->createPresignedRequest($command, $expiry)->getUri();
    }
}
