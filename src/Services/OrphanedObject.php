<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

/**
 * A bucket object that no stored file and no live upload session points at.
 *
 * @internal
 */
final class OrphanedObject
{
    public function __construct(
        public readonly string $disk,
        public readonly string $key,
        public readonly int $size,
    ) {}
}
