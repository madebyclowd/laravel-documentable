<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * A batch hook that answers with a boolean instead of the viewable collection.
 */
class BrokenBatchPolicy
{
    public function viewMany(Authenticatable $user, Collection $documents): bool
    {
        return true;
    }
}
