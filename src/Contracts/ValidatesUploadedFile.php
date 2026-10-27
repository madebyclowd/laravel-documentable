<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Contracts;

use MadeByClowd\Documentable\Models\DocumentType;

/**
 * A check on what an upload really is, beyond the MIME type fileinfo guesses: usually that its first bytes carry
 * the format's signature. Register implementations per document type under `documentable.validators`.
 *
 * Implementations get only the first bytes (a few KiB), so they stay cheap on an object of any size, and must be
 * pure: the same input gives the same answer.
 *
 * @api
 */
interface ValidatesUploadedFile
{
    /**
     * @param  string  $head  the first bytes of the object, up to 8 KiB
     * @return string|null null when the file is acceptable, otherwise the reason it is not, written for the
     *                     person who uploaded it
     */
    public function validate(DocumentType $type, string $filename, string $head): ?string;
}
