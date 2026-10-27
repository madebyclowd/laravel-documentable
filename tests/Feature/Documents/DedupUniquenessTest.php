<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Feature\Integrity\BuildsIntegrityFixtures;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Identical bytes on one disk share one stored file, and the database says so: `(file_hash, disk)` is unique.
 * The disk is part of the key because a blob only exists on the disk it was written to.
 */
class DedupUniquenessTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.other_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
        Storage::fake('other_disk');
    }

    private function typeOnDisk(string $code, string $disk): DocumentType
    {
        return DocumentType::create(['code' => $code, 'name' => $code, 'max_size_mb' => 5, 'disk' => $disk, 'path_prefix' => strtolower($code), 'allows_multiple' => true]);
    }

    private function pdf(string $name = 'a.pdf', string $content = '%PDF-1.4 same bytes'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    public function test_the_database_rejects_a_second_stored_file_for_the_same_hash_and_disk(): void
    {
        $this->storageFile('hash-1', 'test_disk');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->storageFile('hash-1', 'test_disk');
    }

    public function test_the_same_hash_on_another_disk_is_allowed(): void
    {
        $this->storageFile('hash-1', 'test_disk');
        $this->storageFile('hash-1', 'other_disk');

        $this->assertSame(2, StorageFile::count());
    }

    public function test_identical_bytes_for_types_on_different_disks_keep_one_blob_per_disk(): void
    {
        $service = $this->app->make(DocumentService::class);
        $onA = $service->upload($this->pdf(), $this->typeOnDisk('ON_A', 'test_disk'), $this->owner());
        $onB = $service->upload($this->pdf(), $this->typeOnDisk('ON_B', 'other_disk'), $this->owner());

        $this->assertNotSame($onA->storage_file_id, $onB->storage_file_id);
        $this->assertSame('test_disk', $onA->storageFile->disk);
        $this->assertSame('other_disk', $onB->storageFile->disk);
        Storage::disk('other_disk')->assertExists($onB->storageFile->path);
    }

    public function test_identical_bytes_on_one_disk_still_share_one_stored_file(): void
    {
        $service = $this->app->make(DocumentService::class);
        $type = $this->typeOnDisk('SHARED', 'test_disk');

        $first = $service->upload($this->pdf(), $type, $this->owner());
        $second = $service->upload($this->pdf('b.pdf'), $type, $this->owner());

        $this->assertSame($first->storage_file_id, $second->storage_file_id);
        $this->assertSame(1, StorageFile::count());
    }

    public function test_a_lost_race_adopts_the_winner_and_removes_its_own_blob(): void
    {
        $type = $this->typeOnDisk('RACE', 'test_disk');
        $service = $this->app->make(DocumentService::class);
        $winner = $service->upload($this->pdf(), $type, $this->owner());
        $winnerFile = $winner->storageFile;
        $blobsBefore = Storage::disk('test_disk')->allFiles();

        // The lookup misses, as it does when the winner commits a moment after it, so this upload writes its
        // own copy and only learns about the winner when its insert hits the unique index.
        $this->app->bind(StorageFileRepository::class, fn () => new class extends StorageFileRepository
        {
            private bool $stale = true;

            public function findByHash(string $hash, string $disk, bool $lock = false): ?StorageFile
            {
                if ($this->stale) {
                    $this->stale = false;

                    return null;
                }

                return parent::findByHash($hash, $disk, $lock);
            }
        });

        $loser = $this->app->make(DocumentService::class)->upload($this->pdf('b.pdf'), $type, $this->owner());

        $this->assertSame($winnerFile->id, $loser->storage_file_id);
        $this->assertSame(1, StorageFile::count());
        $this->assertEqualsCanonicalizing($blobsBefore, Storage::disk('test_disk')->allFiles());
    }
}
