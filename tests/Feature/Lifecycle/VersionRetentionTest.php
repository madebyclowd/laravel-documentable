<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Events\DocumentVersionPruned;
use MadeByClowd\Documentable\Jobs\PruneDocumentVersions;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\DocumentPurger;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Services\VersionRetention;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use Mockery;
use RuntimeException;

class VersionRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        $this->service = $this->app->make(DocumentService::class);
        $this->owner = TestModel::create(['name' => 'Owner']);
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'RETENTION_DOC',
            'name' => 'Retention Document',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'allows_multiple' => true,
            'requires_versioning' => true,
        ], $overrides));
    }

    protected function uploadVersion(DocumentType $type, string $content, ?string $groupId = null, ?Carbon $createdAt = null): Document
    {
        $doc = $this->service->upload(
            UploadedFile::fake()->createWithContent("{$content}.txt", $content),
            $type,
            $this->owner,
            [],
            $groupId
        );

        if ($createdAt !== null) {
            $doc->forceFill(['created_at' => $createdAt])->save();
        }

        return $doc->fresh();
    }

    protected function failDeletesWith(string $mode): void
    {
        $real = Storage::disk('test_disk');
        $disk = Mockery::mock($real)->makePartial();
        $expectation = $disk->shouldReceive('delete');

        $mode === 'throw' ? $expectation->andThrow(new RuntimeException('bucket unreachable')) : $expectation->andReturn(false);

        Storage::set('test_disk', $disk);
    }

    public function test_retention_count_only_prunes_versions_beyond_limit(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 2, 'versions_retain_days' => null]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id);

        $retention = $this->app->make(VersionRetention::class);
        $toPrune = $retention->forGroup($v1->document_group_id);

        // Kept: v3 (pos 1, latest), v2 (pos 2). Pruned: v1 (pos 3 > 2)
        $this->assertCount(1, $toPrune);
        $this->assertSame($v1->id, $toPrune->first()->id);
    }

    public function test_retention_ignores_soft_deleted_versions(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 2, 'versions_retain_days' => null]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id);
        $v1->delete();

        $toPrune = $this->app->make(VersionRetention::class)->forGroup($v1->document_group_id);

        $this->assertCount(0, $toPrune, 'v3 and v2 fill the two kept slots; the trashed v1 is neither counted nor pruned.');
    }

    public function test_retention_age_only_prunes_versions_older_than_days(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => null, 'versions_retain_days' => 30]);

        $v1 = $this->uploadVersion($type, 'v1', null, now()->subDays(40));
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id, now()->subDays(10));
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id, now()->subDays(5));

        $retention = $this->app->make(VersionRetention::class);
        $toPrune = $retention->forGroup($v1->document_group_id);

        // v1 is older than 30 days -> pruned. v2 and v3 are newer -> kept.
        $this->assertCount(1, $toPrune);
        $this->assertSame($v1->id, $toPrune->first()->id);
    }

    public function test_retention_both_count_and_age_requires_both_conditions(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 2, 'versions_retain_days' => 30]);

        // v1: pos 4 (beyond count), 40 days old (beyond age) => PRUNED
        $v1 = $this->uploadVersion($type, 'v1', null, now()->subDays(40));
        // v2: pos 3 (beyond count), 10 days old (NOT beyond age) => KEPT
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id, now()->subDays(10));
        // v3: pos 2 (within count), 40 days old (beyond age) => KEPT
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id, now()->subDays(40));
        // v4: pos 1 (latest) => KEPT
        $v4 = $this->uploadVersion($type, 'v4', $v1->document_group_id, now());

        $retention = $this->app->make(VersionRetention::class);
        $toPrune = $retention->forGroup($v1->document_group_id);

        $this->assertCount(1, $toPrune);
        $this->assertSame($v1->id, $toPrune->first()->id);
    }

    public function test_retention_neither_prunes_nothing(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => null, 'versions_retain_days' => null]);

        $v1 = $this->uploadVersion($type, 'v1', null, now()->subDays(100));
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id, now()->subDays(50));

        $retention = $this->app->make(VersionRetention::class);
        $toPrune = $retention->forGroup($v1->document_group_id);

        $this->assertCount(0, $toPrune);
    }

    public function test_retention_never_prunes_the_latest_version(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 1, 'versions_retain_days' => 10]);

        // v1 is the only version, and is 50 days old. Since it is latest, it must NEVER be pruned!
        $v1 = $this->uploadVersion($type, 'v1', null, now()->subDays(50));

        $retention = $this->app->make(VersionRetention::class);
        $toPrune = $retention->forGroup($v1->document_group_id);

        $this->assertCount(0, $toPrune);
    }

    public function test_retention_hold_by_document_id_and_group_id(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 1]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        // Put v1 on hold by document ID
        config()->set('documentable.retention.hold', [$v1->id]);
        $retention = $this->app->make(VersionRetention::class);
        $this->assertCount(0, $retention->forGroup($v1->document_group_id));

        // Put group on hold by group ID
        config()->set('documentable.retention.hold', [$v1->document_group_id]);
        $this->assertCount(0, $retention->forGroup($v1->document_group_id));
    }

    public function test_info_panah_merah_regression_keep_3_upload_5_leaves_3_4_5(): void
    {
        $type = $this->makeType(['versions_kept' => 3, 'versions_retain_days' => null]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id);
        $v4 = $this->uploadVersion($type, 'v4', $v1->document_group_id);
        $v5 = $this->uploadVersion($type, 'v5', $v1->document_group_id);

        // Run the pruning job synchronously for this group
        $job = new PruneDocumentVersions($v1->document_group_id);
        $this->app->call([$job, 'handle']);

        // Assert exactly versions 3, 4, 5 remain in the database and 5 is latest
        $this->assertSame(0, Document::whereIn('id', [$v1->id, $v2->id])->count());
        $this->assertSame(1, Document::where('id', $v3->id)->count());
        $this->assertSame(1, Document::where('id', $v4->id)->count());
        $this->assertSame(1, Document::where('id', $v5->id)->count());

        $this->assertTrue($v5->fresh()->is_latest);
        $this->assertFalse($v4->fresh()->is_latest);
        $this->assertFalse($v3->fresh()->is_latest);
    }

    public function test_pruning_waits_for_the_surrounding_transaction_to_commit(): void
    {
        config(['queue.default' => 'sync']);

        $type = $this->makeType(['versions_kept' => 1]);
        $group = null;

        DB::transaction(function () use ($type, &$group) {
            $v1 = $this->uploadVersion($type, 'v1');
            $group = $v1->document_group_id;
            $this->uploadVersion($type, 'v2', $group);

            $this->assertSame(2, Document::where('document_group_id', $group)->count(), 'The job must not run before the commit.');
        });

        $this->assertSame([2], Document::where('document_group_id', $group)->pluck('version')->all());
    }

    public function test_dedup_safety_when_pruning_shared_and_unshared_blobs(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 1]);

        // Upload v1 and v2 with identical content so they share storage file
        $v1 = $this->uploadVersion($type, 'identical-content');
        $v2 = $this->uploadVersion($type, 'identical-content', $v1->document_group_id);

        $this->assertSame($v1->storage_file_id, $v2->storage_file_id);
        $storageFilePath = $v1->storageFile->path;

        // Pruning v1 should keep the shared blob because v2 still references it
        $job = new PruneDocumentVersions($v1->document_group_id);
        $this->app->call([$job, 'handle']);

        $this->assertDatabaseMissing('documents', ['id' => $v1->id]);
        Storage::disk('test_disk')->assertExists($storageFilePath);
        $this->assertSame(1, StorageFile::count());

        // Now if v2 is purged as well, the blob is deleted
        $this->app->make(DocumentPurger::class)->purge($v2);
        Storage::disk('test_disk')->assertMissing($storageFilePath);
        $this->assertSame(0, StorageFile::count());
    }

    public function test_failed_storage_delete_leaves_version_retries_and_no_pruned_event(): void
    {
        Queue::fake([PruneDocumentVersions::class]);
        Event::fake([DocumentVersionPruned::class]);

        $type = $this->makeType(['versions_kept' => 1]);
        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        $this->failDeletesWith('throw');

        $job = new PruneDocumentVersions($v1->document_group_id);
        $this->app->call([$job, 'handle']);

        // Version stays in database
        $this->assertDatabaseHas('documents', ['id' => $v1->id]);
        Event::assertNotDispatched(DocumentVersionPruned::class);
    }

    public function test_prune_versions_command_dry_run_deletes_nothing(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 1]);
        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        $this->artisan('documents:prune-versions', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run complete: 1 document version(s) would be pruned.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('documents', ['id' => $v1->id]);
    }

    public function test_prune_versions_command_without_dry_run_prunes(): void
    {
        Queue::fake([PruneDocumentVersions::class]);
        Event::fake([DocumentVersionPruned::class]);

        $type = $this->makeType(['versions_kept' => 1]);
        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        $this->artisan('documents:prune-versions')
            ->expectsOutputToContain('Successfully pruned 1 document version(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('documents', ['id' => $v1->id]);
        Event::assertDispatched(DocumentVersionPruned::class, fn (DocumentVersionPruned $e) => $e->document->id === $v1->id);
    }

    public function test_prune_versions_command_fails_for_an_unknown_type(): void
    {
        $this->artisan('documents:prune-versions', ['--type' => 'NO_SUCH_TYPE'])
            ->expectsOutputToContain('Document type [NO_SUCH_TYPE] not found.')
            ->assertExitCode(1);
    }

    public function test_prune_versions_command_accepts_a_type_id_and_reports_when_nothing_is_eligible(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 5]);
        $this->uploadVersion($type, 'v1');

        $this->artisan('documents:prune-versions', ['--type' => $type->id])
            ->expectsOutputToContain('No document versions eligible for pruning.')
            ->assertExitCode(0);
    }

    public function test_prune_versions_command_warns_and_keeps_the_version_when_the_delete_fails(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $type = $this->makeType(['versions_kept' => 1]);
        $v1 = $this->uploadVersion($type, 'v1');
        $this->uploadVersion($type, 'v2', $v1->document_group_id);

        $this->failDeletesWith('throw');

        $this->artisan('documents:prune-versions')
            ->expectsOutputToContain("Failed to purge document version [{$v1->id}].")
            ->expectsOutputToContain('Successfully pruned 0 document version(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('documents', ['id' => $v1->id]);
    }

    public function test_retention_selects_nothing_for_an_unknown_or_malformed_group(): void
    {
        $retention = $this->app->make(VersionRetention::class);

        $this->assertCount(0, $retention->forGroup('not-a-uuid'));
        $this->assertCount(0, $retention->forGroup((string) Str::uuid()));
    }

    public function test_prune_versions_command_filters_by_type(): void
    {
        Queue::fake([PruneDocumentVersions::class]);

        $typeA = $this->makeType(['code' => 'TYPE_A', 'versions_kept' => 1]);
        $typeB = $this->makeType(['code' => 'TYPE_B', 'versions_kept' => 1]);

        $vA1 = $this->uploadVersion($typeA, 'a1');
        $vA2 = $this->uploadVersion($typeA, 'a2', $vA1->document_group_id);

        $vB1 = $this->uploadVersion($typeB, 'b1');
        $vB2 = $this->uploadVersion($typeB, 'b2', $vB1->document_group_id);

        $this->artisan('documents:prune-versions', ['--type' => 'TYPE_A'])
            ->expectsOutputToContain('Successfully pruned 1 document version(s).')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('documents', ['id' => $vA1->id]);
        $this->assertDatabaseHas('documents', ['id' => $vB1->id]);
    }
}
