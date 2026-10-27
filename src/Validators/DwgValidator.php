<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Validators;

use MadeByClowd\Documentable\Contracts\ValidatesUploadedFile;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * An AutoCAD drawing starts with its release as ASCII: `AC10` followed by two digits (AC1015, AC1032, ...).
 *
 * @api
 */
final class DwgValidator implements ValidatesUploadedFile
{
    public function validate(DocumentType $type, string $filename, string $head): ?string
    {
        return preg_match('/^AC10\d\d/', $head) === 1 ? null : 'The file does not look like a DWG drawing.';
    }
}
