<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use MadeByClowd\Documentable\Services\VersionPruner;
use MadeByClowd\Documentable\Services\VersionRetention;

/**
 * Prunes old document versions beyond the type's versions_kept and versions_retain_days policies.
 * Dispatched after commit by the attach step. Retries with backoff if storage delete fails.
 */
final class PruneDocumentVersions implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $documentGroupId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(VersionRetention $retention, VersionPruner $pruner): void
    {
        foreach ($retention->forGroup($this->documentGroupId) as $document) {
            if (! $pruner->prune($document)) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 120);

                return;
            }
        }
    }
}
