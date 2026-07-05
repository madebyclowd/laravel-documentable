<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Operational visibility command, not a data-mutating one — mirrors the
 * reference package's `sequence:list`.
 */
class ListDocumentTypesCommand extends Command
{
    protected $signature = 'documents:list';

    protected $description = 'List registered document types with usage counts.';

    public function handle(): int
    {
        $types = DocumentType::withTrashed()
            ->withCount([
                'documents as latest_count' => fn ($query) => $query->whereNotNull('latest_marker'),
                'documents as total_versions' => fn ($query) => $query,
                'documents as pending_count' => fn ($query) => $query->where('status', 'pending'),
            ])
            ->get();

        if ($types->isEmpty()) {
            $this->info('No document types registered yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['Code', 'Name', 'Active', 'Latest Docs', 'Total Versions', 'Pending'],
            $types->map(fn (DocumentType $type) => [
                $type->code,
                $type->name,
                $type->trashed() ? 'no' : 'yes',
                $type->latest_count,
                $type->total_versions,
                $type->pending_count,
            ])
        );

        return self::SUCCESS;
    }
}
