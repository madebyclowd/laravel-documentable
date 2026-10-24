<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `storage_files.path` was varchar(255) while a storage key can be 1,024 bytes (a deep prefix plus a long
 * generated name), so PostgreSQL and strict MySQL rejected the row at the end of an otherwise successful
 * upload. The column is not indexed, so `text` costs nothing. Safe to run twice and on a table an app already
 * widened itself.
 */
return new class extends Migration
{
    private const TEXT_TYPES = ['text', 'mediumtext', 'longtext'];

    public function up(): void
    {
        if (! Schema::hasTable('storage_files') || $this->isText()) {
            return;
        }

        Schema::table('storage_files', fn (Blueprint $table) => $table->text('path')->change());
    }

    public function down(): void
    {
        if (! Schema::hasTable('storage_files')) {
            return;
        }

        if (DB::table('storage_files')->whereRaw('LENGTH(path) > 255')->exists()) {
            throw new RuntimeException('Cannot narrow storage_files.path back to 255 characters: stored files have longer paths.');
        }

        Schema::table('storage_files', fn (Blueprint $table) => $table->string('path')->change());
    }

    private function isText(): bool
    {
        foreach (Schema::getColumns('storage_files') as $definition) {
            if ($definition['name'] === 'path') {
                return in_array($definition['type_name'], self::TEXT_TYPES, true);
            }
        }

        return false;
    }
};
