<?php

namespace MadeByClowd\Documentable\Events;

use MadeByClowd\Documentable\Models\Document;

class DocumentDeleted
{
    public function __construct(public Document $document) {}
}
