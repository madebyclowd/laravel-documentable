<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('multipart_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('path');
            $table->string('upload_id');

            // String, not typed FK — same reasoning as documents.documentable_id:
            // must work against arbitrary consumer user PK types (int or uuid).
            $table->string('user_id');

            $table->foreignUuid('document_type_id')->constrained('document_types');

            // Nullable for now; phase 4's reaper is what actually reads this to
            // decide whether a session is abandoned. Column exists here so that
            // migration is additive, not a schema change to this table.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index(['path', 'upload_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multipart_uploads');
    }
};
