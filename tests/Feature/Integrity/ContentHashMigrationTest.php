<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Tests\TestCase;

class ContentHashMigrationTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migration = require __DIR__.'/../../../database/migrations/2026_10_28_000015_add_content_hash_to_storage_files.php';
    }

    public function test_migration_adds_column_and_backfills_64_hex_hashes(): void
    {
        // First drop the column to simulate upgrading install
        if (Schema::hasColumn('storage_files', 'content_hash')) {
            $this->migration->down();
        }

        $this->assertFalse(Schema::hasColumn('storage_files', 'content_hash'));

        $validHexHash1 = hash('sha256', 'file one');
        $validHexHash2 = hash('sha256', 'file two');
        $scopedHash = 'tenant-1:'.$validHexHash1;
        $pendingHash = 'pending:'.(string) Str::uuid();

        $id1 = (string) Str::uuid();
        $id2 = (string) Str::uuid();
        $id3 = (string) Str::uuid();
        $id4 = (string) Str::uuid();

        DB::table('storage_files')->insert([
            ['id' => $id1, 'file_hash' => $validHexHash1, 'disk' => 'test_disk', 'path' => 'p1', 'mime_type' => 'text/plain', 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $id2, 'file_hash' => $validHexHash2, 'disk' => 'test_disk', 'path' => 'p2', 'mime_type' => 'text/plain', 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $id3, 'file_hash' => $scopedHash, 'disk' => 'test_disk', 'path' => 'p3', 'mime_type' => 'text/plain', 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['id' => $id4, 'file_hash' => $pendingHash, 'disk' => 'test_disk', 'path' => 'p4', 'mime_type' => 'text/plain', 'size_bytes' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->migration->up();

        $this->assertTrue(Schema::hasColumn('storage_files', 'content_hash'));
        $this->assertTrue(Schema::hasIndex('storage_files', ['content_hash']));

        $row1 = DB::table('storage_files')->where('id', $id1)->first();
        $row2 = DB::table('storage_files')->where('id', $id2)->first();
        $row3 = DB::table('storage_files')->where('id', $id3)->first();
        $row4 = DB::table('storage_files')->where('id', $id4)->first();

        $this->assertSame($validHexHash1, $row1->content_hash);
        $this->assertSame($validHexHash2, $row2->content_hash);
        $this->assertNull($row3->content_hash);
        $this->assertNull($row4->content_hash);
    }
}
