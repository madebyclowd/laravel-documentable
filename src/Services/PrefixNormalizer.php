<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use InvalidArgumentException;

/**
 * Normalizes directory and object prefixes so comparisons and S3 listings never cross prefix boundaries.
 *
 * @internal
 */
final class PrefixNormalizer
{
    /**
     * Normalizes a storage path prefix to exactly one trailing slash, refusing empty or root prefixes.
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string $prefix): string
    {
        $trimmed = trim($prefix, '/');

        if ($trimmed === '') {
            throw new InvalidArgumentException('Storage prefix cannot be empty or root.');
        }

        return $trimmed.'/';
    }
}
