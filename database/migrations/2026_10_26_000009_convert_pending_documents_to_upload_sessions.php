<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The `pending` document status is gone: an upload waiting for its owner is now an upload session.
 *
 * - A pending document still inside its expiry becomes an `uploaded` session that points at the same stored file,
 *   and the document row is removed.
 * - A pending document past its expiry is removed. Its stored file stays, and `documents:clean-orphaned
 *   --storage-files` deletes it once nothing references it.
 *
 * Committed documents without an owner are left alone; `documents:upgrade-check` reports them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('documents') || ! Schema::hasTable('upload_sessions')) {
            return;
        }

        $now = now();

        DB::table('documents')->where('status', 'pending')->orderBy('created_at')->each(function ($document) use ($now) {
            $storageFile = DB::table('storage_files')->where('id', $document->storage_file_id)->first();
            $expiresAt = $document->expires_at ? Carbon::parse($document->expires_at) : null;

            if ($storageFile !== null && $expiresAt !== null && $expiresAt->isFuture()) {
                DB::table('upload_sessions')->insert([
                    'id' => strtolower((string) Str::ulid()),
                    'user_id' => $document->created_by,
                    'document_type_id' => $document->document_type_id,
                    'disk' => $storageFile->disk,
                    'kind' => 'single',
                    'client_filename' => $document->client_filename,
                    'path' => $storageFile->path,
                    'declared_size' => $storageFile->size_bytes,
                    'declared_mime' => $storageFile->mime_type,
                    'status' => 'uploaded',
                    'storage_file_id' => $storageFile->id,
                    'expires_at' => $expiresAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('documents')->where('id', $document->id)->delete();
        });
    }

    public function down(): void
    {
        // The pending status no longer exists, so there is nothing to restore.
    }
};
