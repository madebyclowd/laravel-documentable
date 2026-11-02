<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_types') || Schema::hasColumn('document_types', 'versions_kept')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table) {
            $table->unsignedInteger('versions_kept')->nullable()->after('allows_multiple');
            $table->unsignedInteger('versions_retain_days')->nullable()->after('versions_kept');
            $table->string('on_delete_latest')->default('promote')->after('versions_retain_days');
        });

        DB::table('document_types')->update([
            'versions_kept' => DB::raw('max_versions_to_keep'),
        ]);

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('max_versions_to_keep');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_types') || ! Schema::hasColumn('document_types', 'versions_kept')) {
            return;
        }

        Schema::table('document_types', function (Blueprint $table) {
            $table->unsignedInteger('max_versions_to_keep')->nullable()->after('allows_multiple');
        });

        DB::table('document_types')->update([
            'max_versions_to_keep' => DB::raw('versions_kept'),
        ]);

        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn(['versions_kept', 'versions_retain_days', 'on_delete_latest']);
        });
    }
};
