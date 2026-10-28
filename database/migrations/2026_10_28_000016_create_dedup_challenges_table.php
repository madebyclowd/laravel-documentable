<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\Config\DocumentableConfig;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('dedup_challenges')) {
            return;
        }

        Schema::create('dedup_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $keyType = app(DocumentableConfig::class)->keyType;
            match ($keyType) {
                'int' => $table->unsignedBigInteger('user_id')->nullable(),
                'uuid' => $table->uuid('user_id')->nullable(),
                'ulid' => $table->ulid('user_id')->nullable(),
                default => $table->string('user_id')->nullable(),
            };

            $table->foreignUuid('document_type_id')->constrained('document_types');
            $table->foreignUuid('storage_file_id')->nullable()->constrained('storage_files')->nullOnDelete();
            $table->string('scope_key');
            $table->string('nonce', 64);
            $table->json('ranges');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['expires_at', 'consumed_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dedup_challenges');
    }
};
