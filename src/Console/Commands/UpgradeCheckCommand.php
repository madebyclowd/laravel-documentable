<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;
use MadeByClowd\Documentable\Integrity\IntegrityReport;

/**
 * Read-only preflight for the schema constraints. Run it before `php artisan migrate` on an existing install:
 * the migrations that add the constraints refuse to run while this command reports a problem.
 */
class UpgradeCheckCommand extends Command
{
    protected $signature = 'documents:upgrade-check';

    protected $description = 'Report data that would block the integrity constraints (writes nothing).';

    public function handle(IntegrityInspector $inspector): int
    {
        $report = $inspector->inspect();

        $this->renderBlockingFindings($report);
        $this->renderKeyTypeFindings($report);
        $this->renderNotices($report);

        if (! $report->hasBlockingFindings()) {
            $this->info('No integrity problems found.');

            return self::SUCCESS;
        }

        if ($report->hasRepairableFindings()) {
            $this->line('Review the fixes with `php artisan documents:repair --dry-run`, then apply them with `--apply`.');
        }

        return self::FAILURE;
    }

    private function renderBlockingFindings(IntegrityReport $report): void
    {
        if ($report->duplicateSlots !== []) {
            $this->error(count($report->duplicateSlots).' owner and single-slot type pair(s) have more than one latest document:');

            foreach ($report->duplicateSlots as $slot) {
                $this->line("  {$slot->ownerType}:{$slot->ownerId}, document type {$slot->documentTypeId} (best candidate first)");
                array_map(fn (string $id) => $this->line("    - {$id}"), $slot->documentIds);
            }
        }

        if ($report->duplicateStorageFiles !== []) {
            $this->error(count($report->duplicateStorageFiles).' (file_hash, disk) pair(s) have more than one stored file:');

            foreach ($report->duplicateStorageFiles as $duplicate) {
                $this->line("  {$duplicate->fileHash} on disk {$duplicate->disk} (oldest first)");
                array_map(fn (string $id) => $this->line("    - {$id}"), $duplicate->storageFileIds);
            }
        }

        if ($report->documentsMissingStorageFile !== []) {
            $this->error(count($report->documentsMissingStorageFile).' document(s) point at a stored file that does not exist and cannot be repaired automatically:');
            array_map(fn (string $id) => $this->line("    - {$id}"), $report->documentsMissingStorageFile);
            $this->line('Restore the stored file row from a backup, or delete these documents, before upgrading.');
        }
    }

    private function renderKeyTypeFindings(IntegrityReport $report): void
    {
        if ($report->documentsOutsideKeyType === []) {
            return;
        }

        $this->error(count($report->documentsOutsideKeyType)." document(s) have an owner key that does not fit key_type '{$report->keyType}' and cannot be repaired automatically:");
        array_map(fn (string $id) => $this->line("    - {$id}"), $report->documentsOutsideKeyType);
        $this->line("Set key_type back to 'string' if your owners have different key types, or fix these owners' keys.");
    }

    private function renderNotices(IntegrityReport $report): void
    {
        if ($report->openUploadSessions > 0) {
            $this->warn("{$report->openUploadSessions} upload session(s) still open. They keep working, but let them finish or expire before a maintenance window.");
        }

        if ($report->ownerlessDocuments > 0) {
            $this->warn("{$report->ownerlessDocuments} committed document(s) have no owner. That is valid; they are listed so it is a decision rather than a surprise.");
        }
    }
}
