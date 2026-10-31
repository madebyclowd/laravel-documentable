<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use MadeByClowd\Documentable\Models\Document;
use RuntimeException;

/**
 * @api
 */
class CannotDeleteLatestVersion extends RuntimeException
{
    public function __construct(public readonly Document $document)
    {
        parent::__construct("Cannot delete latest version of document [{$document->id}] because the document type policy is block.");
    }
}
