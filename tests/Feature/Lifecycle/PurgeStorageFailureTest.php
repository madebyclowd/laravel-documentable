<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Events\DocumentPurged;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * `purge()` used to ignore what `Storage::delete()` returned. A disk configured with `throw => false` (the
 * Laravel default for s3) reports a failed delete as `false`, so the `StorageFile` row, the only record of the
 * orphaned blob, was deleted anyway and the object was lost to the package for good.
 */
class PurgeStorageFailureTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        $this->service = $this->app->make(DocumentService::class);
        $this->type = DocumentType::create([
            'code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'docs',
            'allows_multiple' => true, 'requires_versioning' => false,
        ]);
    }

    protected function upload(string $content = 'content', ?TestModel $owner = null): Document
    {
        return $this->service->upload(
            UploadedFile::fake()->createWithContent('a.txt', $content),
            $this->type,
            $owner ?? TestModel::create(['name' => 'owner'])
        );
    }

    /**
     * Swaps the faked disk for one whose delete() fails the given way, keeping everything else real.
     */
    protected function failDeletesWith(string $mode): void
    {
        $real = Storage::disk('test_disk');
        $disk = Mockery::mock($real)->makePartial();
        $expectation = $disk->shouldReceive('delete');

        $mode === 'throw' ? $expectation->andThrow(new RuntimeException('bucket unreachable')) : $expectation->andReturn(false);

        Storage::set('test_disk', $disk);
    }

    public function test_a_normal_purge_removes_the_blob_and_the_storage_row(): void
    {
        $document = $this->upload();
        $path = $document->storageFile->path;

        $this->assertTrue($this->service->purge($document));

        Storage::disk('test_disk')->assertMissing($path);
        $this->assertSame(0, StorageFile::count());
    }

    public function test_a_blob_shared_with_another_document_survives_a_purge(): void
    {
        $first = $this->upload('same bytes', TestModel::create(['name' => 'one']));
        $second = $this->upload('same bytes', TestModel::create(['name' => 'two']));
        $this->assertSame($first->storage_file_id, $second->storage_file_id);

        $this->assertTrue($this->service->purge($first));

        Storage::disk('test_disk')->assertExists($second->storageFile->path);
        $this->assertSame(1, StorageFile::count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function failureModes(): array
    {
        return ['delete returns false' => ['false'], 'delete throws' => ['throw']];
    }

    #[DataProvider('failureModes')]
    public function test_a_failed_blob_delete_keeps_the_storage_row_logs_and_reports_failure(string $mode): void
    {
        $document = $this->upload();
        $this->failDeletesWith($mode);
        Log::shouldReceive('error')->once();
        Event::fake([DocumentPurged::class]);

        $result = $this->service->purge($document);

        $this->assertFalse($result);
        $this->assertSame(1, StorageFile::count());
        $this->assertSame(0, Document::withTrashed()->count());
        Event::assertDispatched(DocumentPurged::class, fn (DocumentPurged $event) => $event->storageFileAlsoDeleted === false);
    }

    public function test_the_storage_sweep_retries_the_delete_and_removes_the_row_once_it_succeeds(): void
    {
        $document = $this->upload();
        $path = $document->storageFile->path;
        $this->failDeletesWith('false');
        Log::shouldReceive('error')->once();
        $this->service->purge($document);
        Storage::set('test_disk', Storage::fake('test_disk'));
        Storage::disk('test_disk')->put($path, 'content');

        $this->artisan('documents:clean-orphaned', ['--storage-files' => true, '--hours' => 0])->assertExitCode(0);

        Storage::disk('test_disk')->assertMissing($path);
        $this->assertSame(0, StorageFile::count());
    }

    public function test_the_storage_sweep_leaves_referenced_and_recent_files_alone(): void
    {
        $referenced = $this->upload('referenced');
        $trashed = $this->upload('trashed');
        $this->service->delete($trashed);
        $recentOrphan = StorageFile::create([
            'file_hash' => 'orphan', 'disk' => 'test_disk', 'path' => 'docs/recent', 'mime_type' => 'text/plain', 'size_bytes' => 1,
        ]);
        Storage::disk('test_disk')->put('docs/recent', 'x');

        $this->artisan('documents:clean-orphaned', ['--storage-files' => true])->assertExitCode(0);

        $this->assertNotNull($referenced->storageFile->fresh());
        $this->assertNotNull($trashed->storageFile->fresh());
        $this->assertNotNull($recentOrphan->fresh());
        Storage::disk('test_disk')->assertExists('docs/recent');
    }

    public function test_the_storage_sweep_is_opt_in(): void
    {
        $orphan = StorageFile::create([
            'file_hash' => 'orphan', 'disk' => 'test_disk', 'path' => 'docs/old', 'mime_type' => 'text/plain', 'size_bytes' => 1,
        ]);
        $orphan->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertNotNull($orphan->fresh());
    }

    public function test_sync_types_warns_about_a_disk_that_hides_failed_deletes(): void
    {
        config()->set('filesystems.disks.quiet_disk', ['driver' => 'local', 'root' => sys_get_temp_dir(), 'throw' => false]);
        config()->set('documentable.types', ['DOC' => ['name' => 'Doc', 'max_size_mb' => 1, 'disk' => 'quiet_disk', 'path_prefix' => 'docs']]);

        $this->artisan('documents:sync-types')
            ->expectsOutputToContain("Disk [quiet_disk] has 'throw' => false")
            ->assertExitCode(0);
    }
}
