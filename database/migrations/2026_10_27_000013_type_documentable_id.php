<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;

/**
 * Gives `documents.documentable_id` the type of `documentable.key_type`. The column was created as a string so
 * one table could serve owners of any key type; a typed column lets the database use its index and removes the
 * casts PostgreSQL needs for integer owners.
 *
 * Fresh installs create the string column and this migration converts the empty table, so a new and an
 * upgraded install end up with the same schema. `string` (the default) changes nothing.
 *
 * Converting rewrites the table, so on MySQL writes to `documents` wait while it runs; PostgreSQL rewrites it
 * under an exclusive lock for `int` and `uuid` as well. Run it in a maintenance window on a large table.
 *
 * A value that does not fit the type would fail the conversion halfway, so the migration checks every row first
 * and stops with the command that lists them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }

        $keyType = app(DocumentableConfig::class)->keyType;

        if ($keyType === 'string') {
            return;
        }

        $misfits = count(app(IntegrityInspector::class)->inspect()->documentsOutsideKeyType);

        if ($misfits > 0) {
            throw new RuntimeException(
                "{$misfits} document(s) have an owner key that does not fit key_type '{$keyType}', so documents.documentable_id cannot be converted. "
                .'Run `php artisan documents:upgrade-check` to list them. Set key_type back to \'string\' if your owners have different key types, '
                .'or fix those keys, then run the migration again.'
            );
        }

        $this->convertTo($keyType);
    }

    public function down(): void
    {
        if (! Schema::hasTable('documents')) {
            return;
        }

        $this->convertTo('string');
    }

    private function convertTo(string $keyType): void
    {
        if (DB::connection()->getDriverName() === 'pgsql' && in_array($keyType, ['int', 'uuid'], true)) {
            // PostgreSQL does not cast a string column to these types on its own, and Laravel 12's `change()` has
            // no way to pass the USING clause, so the statement is written out.
            $type = $keyType === 'int' ? 'bigint' : 'uuid';
            DB::statement("alter table documents alter column documentable_id type {$type} using documentable_id::{$type}");

            return;
        }

        Schema::table('documents', function (Blueprint $table) use ($keyType) {
            $column = match ($keyType) {
                'int' => $table->unsignedBigInteger('documentable_id'),
                'uuid' => $table->uuid('documentable_id'),
                'ulid' => $table->ulid('documentable_id'),
                default => $table->string('documentable_id'),
            };

            $column->nullable()->change();
        });
    }
};
