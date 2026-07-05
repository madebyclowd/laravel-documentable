<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_files', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Plain index, not unique, on purpose: phase 5 makes dedup scope pluggable
            // (global vs tenant-scoped), which changes what the real uniqueness key is.
            $table->string('file_hash', 64)->index();
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_files');
    }
};
