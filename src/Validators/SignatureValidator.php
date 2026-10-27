<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Validators;

use MadeByClowd\Documentable\Contracts\ValidatesUploadedFile;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Accepts a file whose first bytes start with one of a format's magic numbers.
 *
 * @api Extend it to add a format: list the signatures and name the format.
 */
abstract class SignatureValidator implements ValidatesUploadedFile
{
    /**
     * @return list<string> the byte sequences a file of this format can start with
     */
    abstract protected function signatures(): array;

    /**
     * The format's name as a person would write it in the rejection message.
     */
    abstract protected function format(): string;

    public function validate(DocumentType $type, string $filename, string $head): ?string
    {
        foreach ($this->signatures() as $signature) {
            if (str_starts_with($head, $signature)) {
                return null;
            }
        }

        return "The file does not look like a {$this->format()} file.";
    }
}
