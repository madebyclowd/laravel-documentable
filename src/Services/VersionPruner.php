<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use MadeByClowd\Documentable\Events\DocumentVersionPruned;
use MadeByClowd\Documentable\Models\Document;

/**
 * Permanently removes one version picked by `VersionRetention`. If its stored object cannot be deleted the row
 * is put back, so the version still exists and the next run retries it.
 *
 * @internal
 */
final class VersionPruner
{
    public function __construct(private readonly DocumentPurger $purger) {}

    public function prune(Document $document): bool
    {
        DB::beginTransaction();

        if (! $this->purger->purge($document)) {
            DB::rollBack();

            return false;
        }

        DB::commit();

        Event::dispatch(new DocumentVersionPruned($document));

        return true;
    }
}
