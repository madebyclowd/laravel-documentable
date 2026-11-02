<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use LogicException;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;

/**
 * An upload session was asked to make a move its lifecycle does not allow, such as attaching one that was
 * never uploaded, or uploading one twice.
 */
class IllegalUploadSessionTransition extends LogicException
{
    public function __construct(public readonly UploadSessionStatus $from, public readonly UploadSessionStatus $to)
    {
        parent::__construct("Cannot move an upload session from {$from->value} to {$to->value}.");
    }
}
