<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use MadeByClowd\Documentable\Models\Document;

/**
 * Drift-detector for the versioning invariant: `latest_marker` is set exactly when `is_latest` is true and NULL
 * otherwise. The marker value is the document type id for a single-slot type and the group id for a multi-slot
 * one. Should never find anything if DocumentService is the only writer; exists as a defense-in-depth sanity
 * check for direct DB manipulation and data imports.
 */
class VerifyDocumentIntegrityCommand extends Command
{
    protected $signature = 'documents:verify
        {--repair : Fix drift instead of just reporting it}';

    protected $description = 'Detect (and optionally repair) latest_marker / is_latest drift.';

    public function handle(): int
    {
        $drifted = Document::query()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->whereNotNull('latest_marker')->where('is_latest', false);
                })->orWhere(function ($q) {
                    $q->whereNull('latest_marker')->where('is_latest', true);
                });
            })
            ->get();

        if ($drifted->isEmpty()) {
            $this->info('No latest_marker / is_latest drift detected.');

            return self::SUCCESS;
        }

        $this->warn("{$drifted->count()} document(s) with latest_marker / is_latest drift:");

        $this->table(
            ['ID', 'latest_marker', 'is_latest'],
            $drifted->map(fn (Document $document) => [
                $document->id,
                $document->latest_marker ?? 'NULL',
                $document->is_latest ? 'true' : 'false',
            ])
        );

        if ($this->option('repair')) {
            foreach ($drifted as $document) {
                $document->update(['is_latest' => $document->latest_marker !== null]);
            }

            $this->info('Repaired.');
        }

        return self::SUCCESS;
    }
}
