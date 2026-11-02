<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Validators;

/**
 * @api
 */
final class RarValidator extends SignatureValidator
{
    protected function signatures(): array
    {
        return ["Rar!\x1A\x07"];
    }

    protected function format(): string
    {
        return 'RAR';
    }
}
