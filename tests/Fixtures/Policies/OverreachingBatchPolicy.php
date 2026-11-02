<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use MadeByClowd\Documentable\Models\Document;

/**
 * A batch hook that returns a document it was never given.
 */
class OverreachingBatchPolicy
{
    public static ?Document $stranger = null;

    /**
     * @param  Collection<int, Document>  $documents
     * @return Collection<int, Document>
     */
    public function viewMany(Authenticatable $user, Collection $documents): Collection
    {
        return $documents->push(self::$stranger);
    }
}
