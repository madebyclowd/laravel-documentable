<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Upgrade;

/**
 * @internal
 */
final readonly class Finding
{
    public function __construct(
        public string $rule,
        public string $file,
        public int $line,
        public string $message,
        public string $replacement,
        public string $anchor,
    ) {}
}
