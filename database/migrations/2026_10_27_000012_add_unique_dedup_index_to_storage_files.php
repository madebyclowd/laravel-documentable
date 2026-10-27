<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;

/**
 * Makes `(file_hash, disk)` unique on `storage_files`, so two concurrent uploads of identical bytes cannot
 * create two stored files, and a blob is never shared across disks.
 *
 * `file_hash` holds the dedup scope key, which is the plain hash unless a scope resolver adds a prefix, so the
 * pair is the identity of a stored object. The composite index also serves the old lookups by `file_hash`
 * alone, so the plain index is dropped.
 *
 * Existing duplicates would make the index fail halfway, so the migration stops first and names the commands
 * that merge them.
 */
return new class extends Migration
{
    private const INDEX = 'storage_files_hash_disk_unique';

    public function up(): void
    {
        if (! Schema::hasTable('storage_files') || Schema::hasIndex('storage_files', self::INDEX)) {
            return;
        }

        $duplicates = count(app(IntegrityInspector::class)->inspect()->duplicateStorageFiles);

        if ($duplicates > 0) {
            throw new RuntimeException(
                "{$duplicates} (file_hash, disk) pair(s) have more than one stored file, so the unique dedup index cannot be added. "
                .'Run `php artisan documents:upgrade-check` to list them, review the fix with `php artisan documents:repair --dry-run`, '
                .'apply it with `php artisan documents:repair --apply`, then run the migration again.'
            );
        }

        Schema::table('storage_files', function (Blueprint $table) {
            $table->unique(['file_hash', 'disk'], self::INDEX);
            $table->dropIndex(['file_hash']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('storage_files') || ! Schema::hasIndex('storage_files', self::INDEX)) {
            return;
        }

        Schema::table('storage_files', function (Blueprint $table) {
            $table->index('file_hash');
            $table->dropUnique(self::INDEX);
        });
    }
};
