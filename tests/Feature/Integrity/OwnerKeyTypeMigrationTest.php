<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;

class OwnerKeyTypeMigrationTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require dirname(__DIR__, 3).'/database/migrations/2026_10_27_000013_type_documentable_id.php';
    }

    private function useKeyType(string $keyType): void
    {
        config()->set('documentable.key_type', $keyType);
        $this->app->forgetInstance(DocumentableConfig::class);
    }

    private function ownerKeyColumnType(): string
    {
        return strtolower((string) collect(Schema::getColumns('documents'))->firstWhere('name', 'documentable_id')['type_name']);
    }

    public function test_an_int_key_type_converts_the_column_and_keeps_existing_values(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $document = $this->latestDocument($type, $owner);
        $this->useKeyType('int');

        $this->migration()->up();

        $this->assertStringContainsString('int', $this->ownerKeyColumnType());
        $this->assertSame((int) $owner->getKey(), (int) $document->refresh()->documentable_id);
        $this->assertSame(1, DB::table('documents')->where('documentable_id', $owner->getKey())->count());
    }

    public function test_the_unique_slot_index_still_rejects_a_second_latest_row_after_conversion(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $this->latestDocument($type, $owner, marker: $type->id);
        $this->useKeyType('int');
        $this->migration()->up();

        $this->expectException(UniqueConstraintViolationException::class);

        // PostgreSQL aborts the whole transaction on a violation, so it needs a savepoint. MySQL's ALTER TABLE
        // already committed the test's transaction, so a savepoint would not exist there.
        DB::connection()->getDriverName() === 'pgsql'
            ? DB::transaction(fn () => $this->latestDocument($type, $owner, marker: $type->id))
            : $this->latestDocument($type, $owner, marker: $type->id);
    }

    public function test_a_uuid_key_type_converts_the_column(): void
    {
        $document = $this->latestDocument($this->singleSlotType(), $this->owner());
        Document::whereKey($document->id)->update(['documentable_id' => $uuid = (string) Str::uuid()]);
        $this->useKeyType('uuid');

        $this->migration()->up();

        $this->assertSame($uuid, $document->refresh()->documentable_id);
        $this->assertContains($this->ownerKeyColumnType(), ['uuid', 'char', 'varchar', 'bpchar']);
    }

    public function test_a_ulid_key_type_converts_the_column(): void
    {
        $document = $this->latestDocument($this->singleSlotType(), $this->owner());
        Document::whereKey($document->id)->update(['documentable_id' => $ulid = strtolower((string) Str::ulid())]);
        $this->useKeyType('ulid');

        $this->migration()->up();

        $this->assertSame($ulid, $document->refresh()->documentable_id);
    }

    public function test_the_default_string_key_type_changes_nothing(): void
    {
        $before = $this->ownerKeyColumnType();

        $this->migration()->up();

        $this->assertSame($before, $this->ownerKeyColumnType());
    }

    public function test_it_refuses_while_an_owner_key_does_not_fit_and_leaves_the_column_alone(): void
    {
        $document = $this->latestDocument($this->singleSlotType(), $this->owner());
        Document::whereKey($document->id)->update(['documentable_id' => 'not-a-number']);
        $before = $this->ownerKeyColumnType();
        $this->useKeyType('int');

        try {
            $this->migration()->up();
            $this->fail('The migration converted a column holding a value that is not an integer.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('documents:upgrade-check', $e->getMessage());
            $this->assertStringContainsString("key_type 'int'", $e->getMessage());
        }

        $this->assertSame($before, $this->ownerKeyColumnType());
    }

    public function test_it_is_idempotent(): void
    {
        $this->latestDocument($this->singleSlotType(), $this->owner());
        $this->useKeyType('int');

        $this->migration()->up();
        $this->migration()->up();

        $this->assertStringContainsString('int', $this->ownerKeyColumnType());
    }

    public function test_down_restores_a_string_column(): void
    {
        $owner = $this->owner();
        $document = $this->latestDocument($this->singleSlotType(), $owner);
        $this->useKeyType('int');
        $this->migration()->up();

        $this->migration()->down();

        $this->assertStringNotContainsString('int', $this->ownerKeyColumnType());
        $this->assertSame((string) $owner->getKey(), (string) $document->refresh()->documentable_id);
    }
}
