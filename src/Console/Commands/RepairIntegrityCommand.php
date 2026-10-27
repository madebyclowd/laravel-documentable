<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;
use MadeByClowd\Documentable\Integrity\IntegrityRepairer;
use MadeByClowd\Documentable\Integrity\RepairStep;

/**
 * Fixes what `documents:upgrade-check` reports, in two explicit steps so nothing changes unreviewed: first
 * `--dry-run` prints the plan, then `--apply` executes it.
 */
class RepairIntegrityCommand extends Command
{
    protected $signature = 'documents:repair
        {--dry-run : Print the plan and change nothing}
        {--apply : Execute the plan}';

    protected $description = 'Demote duplicate latest documents and merge duplicate stored files.';

    public function handle(IntegrityInspector $inspector, IntegrityRepairer $repairer): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Pass --dry-run to see the plan or --apply to execute it.');

            return self::INVALID;
        }

        $report = $inspector->inspect();
        $plan = $repairer->plan($report);

        if ($plan === []) {
            $this->info('Nothing to repair.');

            return $this->reportUnrepairable($report->documentsMissingStorageFile);
        }

        foreach ($plan as $step) {
            $this->line(($step->changesData() ? '  - ' : '  = ').$step->description);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing was changed.');

            return $this->reportUnrepairable($report->documentsMissingStorageFile);
        }

        $failed = $repairer->apply($plan);

        if ($failed !== []) {
            $this->error(count($failed).' step(s) did not complete and stay for the next run:');
            array_map(fn (RepairStep $step) => $this->line("  - {$step->description}"), $failed);

            return self::FAILURE;
        }

        $this->info('Repair complete. Run `php artisan documents:upgrade-check` to confirm.');

        return $this->reportUnrepairable($report->documentsMissingStorageFile);
    }

    /**
     * @param  list<string>  $documentIds
     */
    private function reportUnrepairable(array $documentIds): int
    {
        if ($documentIds === []) {
            return self::SUCCESS;
        }

        $this->error(count($documentIds).' document(s) point at a missing stored file and cannot be repaired automatically: '.implode(', ', $documentIds));

        return self::FAILURE;
    }
}
