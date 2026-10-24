<?php

namespace MadeByClowd\Documentable\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use MadeByClowd\Documentable\Models\Document;

class DocumentVersionSuperseded implements ShouldDispatchAfterCommit
{
    public function __construct(public Document $previous, public Document $new) {}
}
