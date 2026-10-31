<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use MadeByClowd\Documentable\Models\Document;

class DocumentVersionPruned implements ShouldDispatchAfterCommit
{
    public function __construct(public Document $document) {}
}
