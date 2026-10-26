<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use InvalidArgumentException;

/**
 * Thrown at boot when `config/documentable.php` holds a value the package cannot work with. The message
 * always names the offending key and says how to fix it, so a bad deploy fails loudly instead of at the
 * first upload.
 */
class InvalidDocumentableConfig extends InvalidArgumentException
{
    public function __construct(public readonly string $key, string $problem, string $fix)
    {
        parent::__construct("Invalid documentable config: documentable.{$key} {$problem}. {$fix}");
    }
}
