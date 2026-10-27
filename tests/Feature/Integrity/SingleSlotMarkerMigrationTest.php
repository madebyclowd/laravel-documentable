<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;

class SingleSlotMarkerMigrationTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require dirname(__DIR__, 3).'/database/migrations/2026_10_27_000011_use_type_id_as_single_slot_latest_marker.php';
    }

    public function test_it_backfills_single_slot_latest_rows_and_leaves_everything_else(): void
    {
        $single = $this->singleSlotType();
        $multi = $this->multiSlotType();
        $owner = $this->owner();
        $latest = $this->latestDocument($single, $owner);
        $superseded = $this->latestDocument($single, $this->owner());
        Document::whereKey($superseded->id)->update(['is_latest' => false, 'latest_marker' => null]);
        $multiLatest = $this->latestDocument($multi, $owner);

        $this->migration()->up();

        $this->assertSame($single->id, $latest->refresh()->latest_marker);
        $this->assertNull($superseded->refresh()->latest_marker);
        $this->assertSame($multiLatest->document_group_id, $multiLatest->refresh()->latest_marker);
    }

    public function test_it_is_idempotent(): void
    {
        $type = $this->singleSlotType();
        $document = $this->latestDocument($type, $this->owner());

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($type->id, $document->refresh()->latest_marker);
    }

    public function test_it_refuses_while_duplicates_exist_and_names_the_commands_to_run(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $first = $this->latestDocument($type, $owner);
        $this->latestDocument($type, $owner);

        try {
            $this->migration()->up();
            $this->fail('The migration ran over duplicate latest rows.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('documents:upgrade-check', $e->getMessage());
            $this->assertStringContainsString('documents:repair --apply', $e->getMessage());
        }

        $this->assertNotSame($type->id, $first->refresh()->latest_marker);
    }

    public function test_it_passes_once_the_repair_has_run(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $this->latestDocument($type, $owner, version: 1);
        $kept = $this->latestDocument($type, $owner, version: 2);

        $this->artisan('documents:repair', ['--apply' => true])->assertExitCode(0);
        $this->migration()->up();

        $this->assertSame($type->id, $kept->refresh()->latest_marker);
    }

    public function test_down_restores_the_group_id_marker(): void
    {
        $type = $this->singleSlotType();
        $document = $this->latestDocument($type, $this->owner());
        $this->migration()->up();

        $this->migration()->down();

        $this->assertSame($document->document_group_id, $document->refresh()->latest_marker);
    }
}
