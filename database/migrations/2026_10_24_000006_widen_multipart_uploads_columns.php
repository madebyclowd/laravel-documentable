<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `path` and `upload_id` were varchar(255), but Cloudflare R2 issues 343 character upload ids and a storage
 * key can be 1,024 bytes, so PostgreSQL (and MySQL in strict mode) rejected the session row at `initiate`.
 *
 * Widening to `text` also means the old (path, upload_id, user_id) index cannot exist: MySQL cannot index
 * a whole `text` column and a 1,024-character varchar would exceed its key length limit. Lookups go through
 * `user_id` instead (one user has a handful of open sessions) and filter the rest on that small set.
 *
 * Every step inspects the current schema first, so the migration is safe to run twice and on a table that an
 * app already widened with its own migration.
 */
return new class extends Migration
{
    private const TABLE = 'multipart_uploads';

    private const TEXT_TYPES = ['text', 'mediumtext', 'longtext'];

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        // The package's own composite index goes first: MySQL refuses to alter a column an index depends on
        // into a type it cannot index. An index the app added itself is not ours to drop.
        foreach ($this->packageCompositeIndexes() as $name) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex($name));
        }

        foreach (['path', 'upload_id'] as $column) {
            if (! $this->isText($column)) {
                Schema::table(self::TABLE, fn (Blueprint $table) => $table->text($column)->change());
            }
        }

        if (! $this->hasIndexOn(['user_id'])) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->index('user_id'));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $tooLong = DB::table(self::TABLE)
            ->whereRaw('LENGTH(path) > 255 OR LENGTH(upload_id) > 255')
            ->exists();

        if ($tooLong) {
            throw new RuntimeException('Cannot narrow multipart_uploads.path and upload_id back to 255 characters: open sessions hold longer values. Abort or let them expire first.');
        }

        if ($this->hasIndexOn(['user_id'])) {
            Schema::table(self::TABLE, fn (Blueprint $table) => $table->dropIndex(['user_id']));
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->string('path')->change();
            $table->string('upload_id')->change();
        });

        Schema::table(self::TABLE, fn (Blueprint $table) => $table->index(['path', 'upload_id', 'user_id']));
    }

    private function isText(string $column): bool
    {
        foreach (Schema::getColumns(self::TABLE) as $definition) {
            if ($definition['name'] === $column) {
                return in_array($definition['type_name'], self::TEXT_TYPES, true);
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function packageCompositeIndexes(): array
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->filter(fn (array $index) => $index['columns'] === ['path', 'upload_id', 'user_id'])
            ->pluck('name')
            ->all();
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOn(array $columns): bool
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->contains(fn (array $index) => $index['columns'] === $columns);
    }
};
