<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Tests\TestCase;

class RetentionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require dirname(__DIR__, 3).'/database/migrations/2026_10_29_000017_add_retention_and_delete_latest_to_document_types.php';
    }

    public function test_migration_carries_max_versions_to_keep_into_versions_kept(): void
    {
        // Drop the migration's columns if migration already ran via RefreshDatabase
        if (Schema::hasColumn('document_types', 'versions_kept')) {
            $this->migration()->down();
        }

        $this->assertTrue(Schema::hasColumn('document_types', 'max_versions_to_keep'));
        $this->assertFalse(Schema::hasColumn('document_types', 'versions_kept'));

        // Insert a document type with max_versions_to_keep set
        $id = (string) Str::uuid();
        DB::table('document_types')->insert([
            'id' => $id,
            'code' => 'MIGRATION_TEST',
            'name' => 'Migration Test',
            'disk' => 's3',
            'path_prefix' => 'tests',
            'max_versions_to_keep' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Run the migration up
        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('document_types', 'versions_kept'));
        $this->assertTrue(Schema::hasColumn('document_types', 'versions_retain_days'));
        $this->assertTrue(Schema::hasColumn('document_types', 'on_delete_latest'));
        $this->assertFalse(Schema::hasColumn('document_types', 'max_versions_to_keep'));

        $row = DB::table('document_types')->where('id', $id)->first();
        $this->assertSame(5, (int) $row->versions_kept);
        $this->assertNull($row->versions_retain_days);
        $this->assertSame('promote', $row->on_delete_latest);

        // Test down
        $this->migration()->down();

        $this->assertTrue(Schema::hasColumn('document_types', 'max_versions_to_keep'));
        $this->assertFalse(Schema::hasColumn('document_types', 'versions_kept'));
        $this->assertFalse(Schema::hasColumn('document_types', 'versions_retain_days'));
        $this->assertFalse(Schema::hasColumn('document_types', 'on_delete_latest'));

        $rowAfterDown = DB::table('document_types')->where('id', $id)->first();
        $this->assertSame(5, (int) $rowAfterDown->max_versions_to_keep);

        // Re-run up to leave database clean
        $this->migration()->up();
    }
}
