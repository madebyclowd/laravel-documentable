<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more rules a document type can enforce on an upload.
 *
 * `allowed_extensions` is a list of lowercase extensions without the dot; null means any. `trust_extension_for_generic_mime`
 * lets a file whose sniffed type is only `application/octet-stream` or `text/plain` through when its extension is on
 * that list, for formats (DXF, DWG on older libmagic, many archives) that fileinfo cannot name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_types') || Schema::hasColumn('document_types', 'allowed_extensions')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table) {
            $table->json('allowed_extensions')->nullable()->after('allowed_mimes');
            $table->boolean('trust_extension_for_generic_mime')->default(false)->after('allowed_extensions');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_types') || ! Schema::hasColumn('document_types', 'allowed_extensions')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn(['allowed_extensions', 'trust_extension_for_generic_mime']);
        });
    }
};
