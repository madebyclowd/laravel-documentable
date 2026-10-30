<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Aws\S3\S3Client;
use InvalidArgumentException;

/**
 * Builds or resolves the S3Client used exclusively for local presigning of URLs.
 *
 * @internal
 */
final class PresignClientFactory
{
    /** @var array<string, S3Client> */
    private array $clients = [];

    /**
     * Resolves the cached S3Client for presigning operations on the given disk.
     */
    public function forDisk(string $disk): S3Client
    {
        if (isset($this->clients[$disk])) {
            return $this->clients[$disk];
        }

        $presignEndpoint = config("documentable.disks.{$disk}.presign_endpoint");

        if ($presignEndpoint === null || $presignEndpoint === '') {
            $client = S3ClientDetector::client($disk);

            if (! $client instanceof S3Client) {
                throw new InvalidArgumentException("Disk [{$disk}] does not provide an S3 client.");
            }

            return $this->clients[$disk] = $client;
        }

        $diskConfig = (array) config("filesystems.disks.{$disk}", []);

        $presignUsePathStyle = config("documentable.disks.{$disk}.presign_use_path_style");
        $usePathStyle = $presignUsePathStyle !== null
            ? (bool) $presignUsePathStyle
            : (bool) ($diskConfig['use_path_style_endpoint'] ?? false);

        $clientConfig = [
            'region' => (string) ($diskConfig['region'] ?? 'us-east-1'),
            'version' => (string) ($diskConfig['version'] ?? 'latest'),
            'endpoint' => (string) $presignEndpoint,
            'use_path_style_endpoint' => $usePathStyle,
            'request_checksum_calculation' => 'when_required',
        ];

        if (isset($diskConfig['credentials']) && is_array($diskConfig['credentials'])) {
            $clientConfig['credentials'] = $diskConfig['credentials'];
        } elseif (isset($diskConfig['key'], $diskConfig['secret'])) {
            $credentials = [
                'key' => $diskConfig['key'],
                'secret' => $diskConfig['secret'],
            ];

            if (! empty($diskConfig['token'])) {
                $credentials['token'] = $diskConfig['token'];
            }

            $clientConfig['credentials'] = $credentials;
        }

        if (isset($diskConfig['handler'])) {
            $clientConfig['handler'] = $diskConfig['handler'];
        }

        return $this->clients[$disk] = new S3Client($clientConfig);
    }

    /**
     * Clears cached clients.
     */
    public function flush(): void
    {
        $this->clients = [];
    }
}
