<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use RuntimeException;

/**
 * Raised when a bucket sweep cannot run safely: the disk is not S3, or a document type's path prefix would reach
 * the whole bucket.
 */
final class BucketSweepRefused extends RuntimeException
{
    public static function notAnS3Disk(string $disk): self
    {
        return new self("Disk [{$disk}] is not an S3 disk.");
    }

    public static function unsafePrefix(string $prefix, string $disk, string $reason): self
    {
        return new self("Invalid path prefix [{$prefix}] for disk [{$disk}]: {$reason}");
    }
}
