<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use LogicException;
use MadeByClowd\Documentable\Enums\DocumentStatus;

/**
 * A document that is still being verified, or was refused, has no download URL.
 *
 * @api
 */
class DocumentNotReady extends LogicException
{
    public function __construct(public readonly string $documentId, public readonly DocumentStatus $status)
    {
        parent::__construct("Document {$documentId} is {$status->value} and cannot be served.");
    }
}
