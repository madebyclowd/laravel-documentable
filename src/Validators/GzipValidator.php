<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Validators;

/**
 * @api
 */
final class GzipValidator extends SignatureValidator
{
    protected function signatures(): array
    {
        return ["\x1F\x8B"];
    }

    protected function format(): string
    {
        return 'gzip';
    }
}
