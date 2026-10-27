<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Validators;

/**
 * @api
 */
final class SevenZipValidator extends SignatureValidator
{
    protected function signatures(): array
    {
        return ["7z\xBC\xAF\x27\x1C"];
    }

    protected function format(): string
    {
        return '7-Zip';
    }
}
