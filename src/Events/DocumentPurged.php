<?php

namespace MadeByClowd\Documentable\Events;

use MadeByClowd\Documentable\Models\Document;

class DocumentPurged
{
    public function __construct(public Document $document, public bool $storageFileAlsoDeleted) {}
}
