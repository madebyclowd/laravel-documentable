<?php

namespace MadeByClowd\Documentable\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use MadeByClowd\Documentable\Models\Document;

class DocumentPurged implements ShouldDispatchAfterCommit
{
    public function __construct(public Document $document, public bool $storageFileAlsoDeleted) {}
}
