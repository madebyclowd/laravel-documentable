<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\VersionPruner;
use MadeByClowd\Documentable\Services\VersionRetention;

class PruneDocumentVersionsCommand extends Command
{
    protected $signature = 'documents:prune-versions
        {--type= : Specific document type code or ID}
        {--dry-run : List documents that would be pruned without deleting}';

    protected $description = 'Prune old document versions according to type retention policies.';

    public function handle(VersionRetention $retention, VersionPruner $pruner): int
    {
        $typeOption = is_string($this->option('type')) ? $this->option('type') : null;

        if ($typeOption !== null) {
            $type = Str::isUuid($typeOption)
                ? DocumentType::withTrashed()->where('id', $typeOption)->orWhere('code', $typeOption)->first()
                : DocumentType::withTrashed()->where('code', $typeOption)->first();

            if ($type === null) {
                $this->error("Document type [{$typeOption}] not found.");

                return self::FAILURE;
            }

            $toPrune = $retention->forType($type);
        } else {
            $toPrune = $retention->all();
        }

        if ($toPrune->isEmpty()) {
            $this->info('No document versions eligible for pruning.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->table(
                ['ID', 'Type', 'Group', 'Version', 'Created At'],
                $toPrune->map(fn (Document $doc) => [
                    $doc->id,
                    $doc->document_type_id,
                    $doc->document_group_id,
                    $doc->version,
                    $doc->created_at?->toIso8601String() ?? '',
                ])
            );
            $this->info("Dry run complete: {$toPrune->count()} document version(s) would be pruned.");

            return self::SUCCESS;
        }

        $prunedCount = 0;

        foreach ($toPrune as $doc) {
            if ($pruner->prune($doc)) {
                $prunedCount++;
            } else {
                $this->warn("Failed to purge document version [{$doc->id}].");
            }
        }

        $this->info("Successfully pruned {$prunedCount} document version(s).");

        return self::SUCCESS;
    }
}
