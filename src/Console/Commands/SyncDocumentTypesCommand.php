<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Repositories\DocumentTypeRepository;

/**
 * Makes the code-first DocumentType catalog (config('documentable.types')) work
 * without an admin CRUD screen — idempotent, safe to run on every deploy
 * alongside `migrate`. Consumers running fully DB-driven mode simply never call
 * this; config('documentable.types') stays empty and the table is managed
 * directly instead.
 */
class SyncDocumentTypesCommand extends Command
{
    protected $signature = 'documents:sync-types
        {--prune : Deactivate (soft-delete) DB types missing from config}';

    protected $description = "Upsert config('documentable.types') definitions into the document_types table.";

    public function handle(DocumentTypeRepository $repository): int
    {
        $defined = config('documentable.types', []);

        if ($defined === []) {
            $this->warn("No types are defined in config('documentable.types'), so there is nothing to sync. That is expected if you manage document types directly in the database.");
        }

        $created = [];
        $updated = [];

        foreach ($defined as $code => $attributes) {
            $type = $repository->updateOrCreate((string) $code, $attributes);

            $type->wasRecentlyCreated ? $created[] = (string) $code : $updated[] = (string) $code;
        }

        $this->info(count($defined).' document type(s) synced.');
        $created === [] || $this->line('Created: '.implode(', ', $created));
        $updated === [] || $this->line('Updated: '.implode(', ', $updated));

        $leftBehind = DocumentType::whereNotIn('code', array_keys($defined))->pluck('code')->all();

        if ($this->option('prune')) {
            $deactivated = $repository->deactivateMissing(array_keys($defined));
            $this->info("{$deactivated} document type(s) deactivated (missing from config).");
        } elseif ($leftBehind !== []) {
            $this->warn('In the database but not in config (kept, use --prune to deactivate): '.implode(', ', $leftBehind));
        }

        $this->warnAboutDisksThatHideFailedDeletes($defined);

        return self::SUCCESS;
    }

    /**
     * A disk with `throw => false` (the Laravel default for s3) answers a failed delete with `false` instead
     * of an exception. The package checks that result, but every other caller on the disk silently loses it.
     *
     * @param  array<string, array<string, mixed>>  $defined
     */
    protected function warnAboutDisksThatHideFailedDeletes(array $defined): void
    {
        $disks = collect($defined)->pluck('disk')->filter()->unique();

        foreach ($disks as $disk) {
            if (config("filesystems.disks.{$disk}.throw") === false) {
                $this->warn("Disk [{$disk}] has 'throw' => false, so a failed delete or write is reported as false instead of an exception. Set 'throw' => true on it in config/filesystems.php.");
            }
        }
    }
}
