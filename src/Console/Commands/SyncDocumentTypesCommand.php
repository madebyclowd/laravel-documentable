<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
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

        foreach ($defined as $code => $attributes) {
            $repository->updateOrCreate((string) $code, $attributes);
        }

        $this->info(count($defined).' document type(s) synced.');

        if ($this->option('prune')) {
            $deactivated = $repository->deactivateMissing(array_keys($defined));
            $this->info("{$deactivated} document type(s) deactivated (missing from config).");
        }

        return self::SUCCESS;
    }
}
