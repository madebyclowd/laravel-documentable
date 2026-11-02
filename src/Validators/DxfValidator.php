<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Validators;

use MadeByClowd\Documentable\Contracts\ValidatesUploadedFile;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Binary DXF has a fixed sentinel. ASCII DXF is a list of group code and value lines that begins either with a
 * comment group (999) or with the group code 0 that opens the first SECTION.
 *
 * @api
 */
final class DxfValidator implements ValidatesUploadedFile
{
    private const BINARY_SENTINEL = "AutoCAD Binary DXF\r\n\x1A\0";

    public function validate(DocumentType $type, string $filename, string $head): ?string
    {
        if (str_starts_with($head, self::BINARY_SENTINEL)) {
            return null;
        }

        $ascii = preg_match('/\A\s*(?:999\r?\n[^\r\n]*\r?\n\s*)?0\r?\nSECTION\r?\n/', $head) === 1;

        return $ascii ? null : 'The file does not look like a DXF drawing.';
    }
}
