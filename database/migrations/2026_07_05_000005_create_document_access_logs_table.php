<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();

            // String, not a fixed-type FK — same portability reasoning as documentable_id.
            $table->string('actor_id')->nullable();
            $table->string('action'); // 'view' | 'download'
            $table->string('ip_address')->nullable();

            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
    }
};
