<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;

/**
 * Makes the unique `latest_marker` index enforce "one latest document per owner" for single-slot types.
 *
 * The marker used to be the document group id, a fresh UUID per row, so two concurrent first uploads each got
 * their own group and both succeeded. For a single-slot type the marker is now the document type id, which is
 * identical for every candidate, so the existing unique index rejects the second. Multi-slot types keep the
 * group id. No index changes; only the values of rows that currently hold a marker.
 *
 * Existing owners that already have two latest documents would violate the index once backfilled, so the
 * migration stops and names the commands that fix them instead of picking a winner on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents') || ! Schema::hasTable('document_types')) {
            return;
        }

        $duplicates = count(app(IntegrityInspector::class)->inspect()->duplicateSlots);

        if ($duplicates > 0) {
            throw new RuntimeException(
                "{$duplicates} owner and document type pair(s) have more than one latest document, so the single-slot constraint cannot be added. "
                .'Run `php artisan documents:upgrade-check` to list them, review the fix with `php artisan documents:repair --dry-run`, '
                .'apply it with `php artisan documents:repair --apply`, then run the migration again.'
            );
        }

        DB::table('documents')
            ->whereNotNull('latest_marker')
            ->whereIn('document_type_id', DB::table('document_types')->where('allows_multiple', false)->select('id'))
            ->update(['latest_marker' => DB::raw('document_type_id')]);
    }

    public function down(): void
    {
        // Restores the previous meaning: the marker is the document's own group id again.
        DB::table('documents')
            ->whereNotNull('latest_marker')
            ->whereIn('document_type_id', DB::table('document_types')->where('allows_multiple', false)->select('id'))
            ->update(['latest_marker' => DB::raw('document_group_id')]);
    }
};
