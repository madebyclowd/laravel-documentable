<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;

class DedupIndexMigrationTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require dirname(__DIR__, 3).'/database/migrations/2026_10_27_000012_add_unique_dedup_index_to_storage_files.php';
    }

    /**
     * An upgrading install has the plain `file_hash` index and no unique one.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->migration()->down();
    }

    public function test_it_adds_the_unique_index_and_drops_the_plain_one(): void
    {
        $this->assertTrue(Schema::hasIndex('storage_files', ['file_hash']));

        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('storage_files', 'storage_files_hash_disk_unique', 'unique'));
        $this->assertFalse(Schema::hasIndex('storage_files', ['file_hash'], 'index'));
    }

    public function test_it_is_idempotent(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('storage_files', 'storage_files_hash_disk_unique'));
    }

    public function test_it_refuses_while_duplicates_exist_and_changes_nothing(): void
    {
        $this->storageFile('hash-1', 'test_disk');
        $this->storageFile('hash-1', 'test_disk');

        try {
            $this->migration()->up();
            $this->fail('The migration ran over duplicate stored files.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('documents:repair --apply', $e->getMessage());
        }

        $this->assertFalse(Schema::hasIndex('storage_files', 'storage_files_hash_disk_unique'));
        $this->assertTrue(Schema::hasIndex('storage_files', ['file_hash']));
    }

    public function test_it_passes_once_the_repair_has_merged_the_duplicates(): void
    {
        $this->storageFile('hash-1', 'test_disk', 'blobs/a');
        $this->storageFile('hash-1', 'test_disk', 'blobs/a');

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(0);
        $this->migration()->up();

        $this->assertTrue(Schema::hasIndex('storage_files', 'storage_files_hash_disk_unique'));
    }

    public function test_down_restores_the_plain_index(): void
    {
        $this->migration()->up();
        $this->migration()->down();

        $this->assertFalse(Schema::hasIndex('storage_files', 'storage_files_hash_disk_unique'));
        $this->assertTrue(Schema::hasIndex('storage_files', ['file_hash']));
    }
}
