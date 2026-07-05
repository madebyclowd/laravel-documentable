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

            // String, not typed FK — must work against arbitrary consumer PK types (int or uuid).
            $table->string('documentable_type')->nullable();
            $table->string('documentable_id')->nullable();
            $table->index(['documentable_type', 'documentable_id']);

            $table->string('client_filename');
            $table->json('metadata')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_latest')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
