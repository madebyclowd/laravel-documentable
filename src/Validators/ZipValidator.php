<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Validators;

/**
 * @api
 */
final class ZipValidator extends SignatureValidator
{
    protected function signatures(): array
    {
        return ["PK\x03\x04", "PK\x05\x06"];
    }

    protected function format(): string
    {
        return 'ZIP or Office Open XML (docx, xlsx, pptx)';
    }
}
