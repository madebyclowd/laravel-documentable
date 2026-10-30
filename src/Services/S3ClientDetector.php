<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Tells whether a configured storage disk is backed by an AWS S3 client, and where its bucket is.
 *
 * @internal
 */
final class S3ClientDetector
{
    /**
     * Resolves the underlying S3Client for a disk if available.
     */
    public static function client(string $disk): ?S3Client
    {
        try {
            $filesystem = Storage::disk($disk);

            if (! method_exists($filesystem, 'getClient')) {
                return null;
            }

            $client = $filesystem->getClient();

            return $client instanceof S3Client ? $client : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Determines whether the disk provides an S3Client instance.
     */
    public static function isS3(string $disk): bool
    {
        return self::client($disk) instanceof S3Client;
    }

    /**
     * The bucket a disk is configured with.
     */
    public static function bucket(string $disk): string
    {
        return (string) config("filesystems.disks.{$disk}.bucket");
    }
}
