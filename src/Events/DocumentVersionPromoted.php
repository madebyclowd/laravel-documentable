<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use MadeByClowd\Documentable\Models\Document;

/**
 * A version became the latest of its group. `$previous` is the version it replaced: deleted by the
 * `promote` policy, demoted by `DocumentService::promote()`, or null when the group had no latest.
 */
class DocumentVersionPromoted implements ShouldDispatchAfterCommit
{
    public function __construct(public ?Document $previous, public Document $promoted) {}
}
