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
            // Plain index, not unique, on purpose: dedup scope is pluggable via
            // ResolvesDedupScope (phase 5) — this column stores whatever that
            // resolver returns (default: the raw sha256 hex; a tenant-scoped
            // resolver might return "tenant-id:sha256hex"), not necessarily a
            // bare hash, hence no fixed length and no unique constraint (scope
            // resolution, not a DB constraint, is what prevents cross-scope
            // collisions).
            $table->string('file_hash')->index();
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
