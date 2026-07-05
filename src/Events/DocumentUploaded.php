<?php

namespace MadeByClowd\Documentable\Events;

use MadeByClowd\Documentable\Models\Document;

class DocumentUploaded
{
    public function __construct(public Document $document) {}
}
