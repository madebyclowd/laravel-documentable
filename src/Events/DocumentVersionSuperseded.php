<?php

namespace MadeByClowd\Documentable\Events;

use MadeByClowd\Documentable\Models\Document;

class DocumentVersionSuperseded
{
    public function __construct(public Document $previous, public Document $new) {}
}
