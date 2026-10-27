<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Validators;

/**
 * @api
 */
final class OleDocumentValidator extends SignatureValidator
{
    protected function signatures(): array
    {
        return ["\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"];
    }

    protected function format(): string
    {
        return 'legacy Office (doc, xls, ppt)';
    }
}
