<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use MadeByClowd\Documentable\Models\Document;

/**
 * Background verification refused a document. Its object was removed and it is never served.
 */
class DocumentRejected implements ShouldDispatchAfterCommit
{
    public function __construct(public Document $document, public string $reason) {}
}
