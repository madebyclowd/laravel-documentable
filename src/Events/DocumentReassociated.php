<?php

namespace MadeByClowd\Documentable\Events;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Models\Document;

class DocumentReassociated
{
    public function __construct(public Document $document, public ?Model $previousOwner) {}
}
