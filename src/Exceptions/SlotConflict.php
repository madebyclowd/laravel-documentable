<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Models\DocumentType;
use RuntimeException;
use Throwable;

/**
 * Two uploads kept claiming the same single-slot document and one could not be placed even after a retry. The
 * caller can safely try again: nothing was written.
 *
 * @api
 */
class SlotConflict extends RuntimeException
{
    public function __construct(public readonly DocumentType $type, public readonly Model $owner, ?Throwable $previous = null)
    {
        parent::__construct("Another upload is replacing the [{$type->code}] document of this owner. Try again.", 0, $previous);
    }
}
