<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use RuntimeException;

/**
 * Raised when attempting to read document contents that exceed the maximum allowed bytes.
 *
 * @api
 */
class DocumentTooLargeToRead extends RuntimeException
{
    public function __construct(
        public readonly string $documentId,
        public readonly int $size,
        public readonly int $limit,
    ) {
        parent::__construct("Document [{$documentId}] size [{$size}] exceeds read limit of [{$limit}] bytes.");
    }
}
