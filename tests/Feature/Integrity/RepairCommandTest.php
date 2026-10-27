<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Tests\TestCase;

class RepairCommandTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test_disk');
    }

    public function test_it_refuses_to_run_without_a_mode(): void
    {
        $this->artisan('documents:repair')
            ->expectsOutputToContain('Pass --dry-run to see the plan or --apply to execute it.')
            ->assertExitCode(2);
    }

    public function test_it_refuses_both_modes_at_once(): void
    {
        $this->artisan('documents:repair', ['--dry-run' => true, '--apply' => true])->assertExitCode(2);
    }

    public function test_dry_run_prints_the_plan_and_writes_nothing(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $kept = $this->latestDocument($type, $owner, version: 2);
        $demoted = $this->latestDocument($type, $owner, version: 1);

        $this->artisan('documents:repair', ['--dry-run' => true])
            ->expectsOutputToContain("Keep document {$kept->id}")
            ->expectsOutputToContain("Demote document {$demoted->id}")
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertExitCode(0);

        $this->assertNotNull($demoted->refresh()->latest_marker);
        $this->assertTrue($demoted->is_latest);
    }

    public function test_apply_keeps_the_highest_version_latest_and_demotes_the_rest(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $low = $this->latestDocument($type, $owner, version: 1);
        $high = $this->latestDocument($type, $owner, version: 3);
        $mid = $this->latestDocument($type, $owner, version: 2);

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(0);

        $this->assertTrue($high->refresh()->is_latest);
        $this->assertNotNull($high->latest_marker);
        foreach ([$low, $mid] as $document) {
            $this->assertFalse($document->refresh()->is_latest);
            $this->assertNull($document->latest_marker);
        }
    }

    public function test_a_version_tie_keeps_the_newest_row(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $older = $this->latestDocument($type, $owner, createdAt: '2026-01-01 10:00:00');
        $newer = $this->latestDocument($type, $owner, createdAt: '2026-01-02 10:00:00');

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(0);

        $this->assertTrue($newer->refresh()->is_latest);
        $this->assertFalse($older->refresh()->is_latest);
    }

    public function test_it_repoints_documents_of_duplicate_storage_files_and_deletes_the_extra_blob(): void
    {
        $this->dropDedupIndex();
        Storage::disk('test_disk')->put('blobs/keep', 'bytes');
        Storage::disk('test_disk')->put('blobs/extra', 'bytes');
        $keep = $this->storageFile('hash-1', path: 'blobs/keep', createdAt: '2026-01-01 10:00:00');
        $extra = $this->storageFile('hash-1', path: 'blobs/extra', createdAt: '2026-01-02 10:00:00');
        $type = $this->multiSlotType();
        $onExtra = $this->latestDocument($type, $this->owner(), $extra);
        $trashedOnExtra = $this->latestDocument($type, $this->owner(), $extra);
        $trashedOnExtra->delete();

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(0);

        $this->assertSame($keep->id, $onExtra->refresh()->storage_file_id);
        $this->assertSame($keep->id, Document::withTrashed()->find($trashedOnExtra->id)->storage_file_id);
        $this->assertNull(StorageFile::find($extra->id));
        Storage::disk('test_disk')->assertExists('blobs/keep');
        Storage::disk('test_disk')->assertMissing('blobs/extra');
    }

    public function test_it_never_deletes_the_blob_when_the_duplicate_rows_share_one_path(): void
    {
        $this->dropDedupIndex();
        Storage::disk('test_disk')->put('blobs/shared', 'bytes');
        $keep = $this->storageFile('hash-1', path: 'blobs/shared', createdAt: '2026-01-01 10:00:00');
        $extra = $this->storageFile('hash-1', path: 'blobs/shared', createdAt: '2026-01-02 10:00:00');
        $document = $this->latestDocument($this->multiSlotType(), $this->owner(), $extra);

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(0);

        Storage::disk('test_disk')->assertExists('blobs/shared');
        $this->assertSame($keep->id, $document->refresh()->storage_file_id);
        $this->assertNull(StorageFile::find($extra->id));
    }

    public function test_a_second_run_finds_nothing_to_do(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $this->latestDocument($type, $owner);
        $this->latestDocument($type, $owner);

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(0);

        $this->artisan('documents:repair', ['--apply' => true])
            ->expectsOutputToContain('Nothing to repair.')
            ->assertExitCode(0);
        $this->artisan('documents:upgrade-check')->assertExitCode(0);
    }

    public function test_every_applied_action_is_logged(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $this->latestDocument($type, $owner);
        $demoted = $this->latestDocument($type, $owner);
        Log::spy();

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(0);

        Log::shouldHaveReceived('info')->withArgs(fn (string $message) => str_contains($message, 'documents:repair') && str_contains($message, 'Demote document'))->atLeast()->once();
    }

    public function test_a_problem_it_cannot_fix_keeps_the_exit_code_failing(): void
    {
        $document = $this->latestDocument($this->singleSlotType(), $this->owner());
        Schema::disableForeignKeyConstraints();
        $document->storageFile->delete();
        Schema::enableForeignKeyConstraints();

        $this->artisan('documents:repair', ['--apply' => true])
            ->expectsOutputToContain('cannot be repaired automatically')
            ->assertExitCode(1);
    }
}
