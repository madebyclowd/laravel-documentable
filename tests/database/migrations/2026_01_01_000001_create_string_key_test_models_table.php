<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixture owner with a string primary key. Idempotent for the same reason as the `test_models` migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('string_key_test_models')) {
            return;
        }

        Schema::create('string_key_test_models', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('string_key_test_models');
    }
};
