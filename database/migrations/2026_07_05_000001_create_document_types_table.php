<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');

            $table->unsignedInteger('max_size_mb')->default(10);
            $table->json('allowed_mimes')->nullable();

            $table->string('disk')->default('s3');
            $table->string('path_prefix')->default('uploads');
            $table->boolean('requires_versioning')->default(false);
            $table->boolean('allows_multiple')->default(false);
            // Nullable = unlimited (no pruning). Pruning job itself is deferred —
            // see docs/implementations/phase-2-versioning-and-multi-document-groups.md.
            $table->unsignedInteger('max_versions_to_keep')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
