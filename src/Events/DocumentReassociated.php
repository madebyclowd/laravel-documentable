<?php

namespace MadeByClowd\Documentable\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Models\Document;

class DocumentReassociated implements ShouldDispatchAfterCommit
{
    public function __construct(public Document $document, public ?Model $previousOwner) {}
}
