<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stand-in owner model used across the feature tests. It is not part of the package, only a fixture to
 * attach documents to. It lives in a migration (not in test boot code) so that `migrate:fresh` creates it
 * together with the package tables on every engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Testbench runs this migration for tests that do not use RefreshDatabase too, against a database
        // that can still hold the table from an earlier run (or from before it lived in a migration).
        if (Schema::hasTable('test_models')) {
            return;
        }

        Schema::create('test_models', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_models');
    }
};
