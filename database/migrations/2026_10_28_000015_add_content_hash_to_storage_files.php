<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `storage_files.content_hash` (string 64, indexed, not unique, nullable) and backfills
 * `content_hash = file_hash` where `file_hash` is a 64-character hexadecimal SHA-256 hash.
 *
 * `file_hash` retains its meaning as the deduplication key (unique with `disk`), while `content_hash`
 * stores the bare SHA-256 hash of the stored blob.
 */
return new class extends Migration
{
    private const COLUMN = 'content_hash';

    public function up(): void
    {
        if (! Schema::hasTable('storage_files')) {
            return;
        }

        if (! Schema::hasColumn('storage_files', self::COLUMN)) {
            Schema::table('storage_files', function (Blueprint $table) {
                $table->string(self::COLUMN, 64)->nullable()->after('file_hash')->index();
            });
        }

        // Backfill content_hash = file_hash where file_hash is 64 hex chars.
        // Using standard ANSI SQL length check and PHP ctype_xdigit ensures identical behavior
        // across SQLite, MySQL, and PostgreSQL without regex syntax divergences.
        DB::table('storage_files')
            ->whereNull(self::COLUMN)
            ->whereRaw('length(file_hash) = 64')
            ->orderBy('id')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    if (ctype_xdigit((string) $row->file_hash)) {
                        DB::table('storage_files')
                            ->where('id', $row->id)
                            ->update([self::COLUMN => $row->file_hash]);
                    }
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('storage_files') || ! Schema::hasColumn('storage_files', self::COLUMN)) {
            return;
        }

        Schema::table('storage_files', function (Blueprint $table) {
            $table->dropIndex([self::COLUMN]);
            $table->dropColumn(self::COLUMN);
        });
    }
};
