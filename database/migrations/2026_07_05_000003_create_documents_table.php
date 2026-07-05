<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('storage_file_id')->constrained('storage_files')->cascadeOnDelete();
            $table->foreignUuid('document_type_id')->constrained('document_types');
            $table->uuid('document_group_id')->nullable();

            // String, not typed FK — must work against arbitrary consumer PK types (int or uuid).
            $table->string('documentable_type')->nullable();
            $table->string('documentable_id')->nullable();
            $table->index(['documentable_type', 'documentable_id']);

            $table->string('client_filename');
            $table->json('metadata')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_latest')->default(true);

            // Nullable "latest marker": document_group_id when this row is the
            // current latest version of its group, NULL otherwise (superseded
            // or soft-deleted). All three engines (MySQL/Postgres/SQLite) treat
            // NULL as distinct in a UNIQUE index — this is the DB-portable
            // replacement for a Postgres/SQLite-only partial unique index.
            $table->uuid('latest_marker')->nullable();

            // Explicit lifecycle status, not inferred from a nullable FK.
            // 'committed' = permanent (whether or not documentable_id is set —
            // uploadDetached()'s intentionally-unowned-forever case is
            // committed with a null documentable_id, a valid permanent state).
            // 'pending' = has expires_at; reaped by documents:clean-orphaned if
            // never transitioned to committed via Document::commit().
            $table->string('status')->default('committed');
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['documentable_type', 'documentable_id', 'document_type_id', 'latest_marker'],
                'documents_latest_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
