<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use InvalidArgumentException;

/**
 * Plans uniform part sizes for multipart uploads across S3-compatible providers.
 *
 * @api
 */
final class PartSizePlanner
{
    public const MIN_PART_SIZE = 5 * 1024 * 1024; // 5 MiB

    public const MAX_PART_SIZE = 5 * 1024 * 1024 * 1024; // 5 GiB

    public const ONE_MIB = 1024 * 1024;

    public const MAX_PARTS = 10000;

    /**
     * Calculates the exact uniform part size (all parts except the last must be this size).
     *
     * @throws InvalidArgumentException
     */
    public function partSize(int $totalBytes): int
    {
        if ($totalBytes <= 0) {
            throw new InvalidArgumentException("Total bytes must be greater than zero, got {$totalBytes}.");
        }

        if ($totalBytes > self::MAX_PARTS * self::MAX_PART_SIZE) {
            throw new InvalidArgumentException("Object of {$totalBytes} bytes requires more than ".self::MAX_PARTS.' parts at the 5 GiB cap.');
        }

        $minForPartLimit = (int) ceil($totalBytes / self::MAX_PARTS);
        $target = max(self::MIN_PART_SIZE, $minForPartLimit);

        $roundedUpToMib = (int) (ceil($target / self::ONE_MIB) * self::ONE_MIB);

        return min(self::MAX_PART_SIZE, $roundedUpToMib);
    }

    /**
     * Calculates the total number of parts for the given byte count.
     *
     * @throws InvalidArgumentException
     */
    public function partCount(int $totalBytes): int
    {
        if ($totalBytes <= 0) {
            throw new InvalidArgumentException("Total bytes must be greater than zero, got {$totalBytes}.");
        }

        $partSize = $this->partSize($totalBytes);

        return (int) ceil($totalBytes / $partSize);
    }
}
