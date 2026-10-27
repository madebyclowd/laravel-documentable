<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Tests\TestCase;

class UpgradeCheckCommandTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    public function test_a_clean_install_passes(): void
    {
        $type = $this->singleSlotType();
        $this->latestDocument($type, $this->owner());

        $this->artisan('documents:upgrade-check')
            ->expectsOutputToContain('No integrity problems found')
            ->assertExitCode(0);
    }

    public function test_duplicate_latest_rows_for_a_single_slot_type_fail_with_the_exact_ids(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $first = $this->latestDocument($type, $owner);
        $second = $this->latestDocument($type, $owner);

        $this->artisan('documents:upgrade-check')
            ->expectsOutputToContain($first->id)
            ->expectsOutputToContain($second->id)
            ->expectsOutputToContain('documents:repair --dry-run')
            ->assertExitCode(1);
    }

    public function test_a_multi_slot_type_may_have_many_latest_rows_per_owner(): void
    {
        $type = $this->multiSlotType();
        $owner = $this->owner();
        $this->latestDocument($type, $owner);
        $this->latestDocument($type, $owner);

        $this->artisan('documents:upgrade-check')->assertExitCode(0);
    }

    public function test_the_same_type_on_different_owners_is_not_a_duplicate(): void
    {
        $type = $this->singleSlotType();
        $this->latestDocument($type, $this->owner());
        $this->latestDocument($type, $this->owner());

        $this->artisan('documents:upgrade-check')->assertExitCode(0);
    }

    public function test_a_superseded_version_is_not_a_duplicate_latest(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $old = $this->latestDocument($type, $owner);
        Document::whereKey($old->id)->update(['is_latest' => false, 'latest_marker' => null]);
        $this->latestDocument($type, $owner, version: 2);

        $this->artisan('documents:upgrade-check')->assertExitCode(0);
    }

    public function test_a_soft_deleted_row_that_still_holds_a_marker_counts_because_the_unique_index_sees_it(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $trashed = $this->latestDocument($type, $owner);
        $trashed->delete();
        $live = $this->latestDocument($type, $owner);

        $this->artisan('documents:upgrade-check')
            ->expectsOutputToContain($trashed->id)
            ->expectsOutputToContain($live->id)
            ->assertExitCode(1);
    }

    public function test_duplicate_storage_files_on_one_disk_fail_but_the_same_hash_on_two_disks_does_not(): void
    {
        $this->dropDedupIndex();
        $a = $this->storageFile('hash-1', 'test_disk');
        $b = $this->storageFile('hash-1', 'test_disk');
        $this->storageFile('hash-2', 'test_disk');
        $this->storageFile('hash-2', 'other_disk');

        $this->artisan('documents:upgrade-check')
            ->expectsOutputToContain($a->id)
            ->expectsOutputToContain($b->id)
            ->assertExitCode(1);
    }

    public function test_a_document_pointing_at_a_missing_storage_file_fails(): void
    {
        $type = $this->singleSlotType();
        $document = $this->latestDocument($type, $this->owner());

        Schema::disableForeignKeyConstraints();
        $document->storageFile->delete();
        Schema::enableForeignKeyConstraints();

        $this->artisan('documents:upgrade-check')
            ->expectsOutputToContain($document->id)
            ->expectsOutputToContain('cannot be repaired automatically')
            ->assertExitCode(1);
    }

    public function test_in_flight_sessions_and_ownerless_documents_are_reported_without_failing(): void
    {
        $type = $this->singleSlotType();
        UploadSession::create([
            'id' => strtolower((string) Str::ulid()),
            'document_type_id' => $type->id,
            'disk' => 'test_disk',
            'kind' => 'single',
            'client_filename' => 'a.pdf',
            'path' => 'a',
            'status' => UploadSessionStatus::Initiated,
            'expires_at' => now()->addHour(),
        ]);
        $document = $this->latestDocument($type, $this->owner());
        Document::whereKey($document->id)->update(['documentable_type' => null, 'documentable_id' => null]);

        $this->artisan('documents:upgrade-check')
            ->expectsOutputToContain('1 upload session(s) still open')
            ->expectsOutputToContain('1 committed document(s) have no owner')
            ->assertExitCode(0);
    }
}
