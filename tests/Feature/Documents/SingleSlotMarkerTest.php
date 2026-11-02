<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Exceptions\SlotConflict;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Services\DocumentAttacher;
use MadeByClowd\Documentable\Tests\Feature\Integrity\BuildsIntegrityFixtures;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * A single-slot type allows one latest document per owner. The database enforces it through `latest_marker`:
 * the document type id for a single-slot type, the group id for a multi-slot one.
 */
class SingleSlotMarkerTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    public function test_a_single_slot_document_takes_the_type_id_as_its_marker(): void
    {
        $type = $this->singleSlotType();

        $document = $this->attacher()->attachStorageFile($this->storageFile(), $type, $this->owner(), 'a.pdf');

        $this->assertSame($type->id, $document->latest_marker);
    }

    public function test_a_multi_slot_document_keeps_the_group_id_as_its_marker(): void
    {
        $document = $this->attacher()->attachStorageFile($this->storageFile(), $this->multiSlotType(), $this->owner(), 'a.pdf');

        $this->assertSame($document->document_group_id, $document->latest_marker);
    }

    public function test_a_new_version_keeps_exactly_one_marker_holder(): void
    {
        $type = $this->singleSlotType(versioned: true);
        $owner = $this->owner();

        $first = $this->attacher()->attachStorageFile($this->storageFile(), $type, $owner, 'a.pdf');
        $second = $this->attacher()->attachStorageFile($this->storageFile(), $type, $owner, 'b.pdf');

        $this->assertNull($first->refresh()->latest_marker);
        $this->assertSame($type->id, $second->latest_marker);
        $this->assertSame(2, $second->version);
    }

    public function test_the_database_rejects_a_second_latest_row_for_the_slot(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $this->attacher()->attachStorageFile($this->storageFile(), $type, $owner, 'a.pdf');

        $this->expectException(UniqueConstraintViolationException::class);

        // A savepoint, because PostgreSQL aborts the whole transaction on a violation.
        DB::transaction(fn () => Document::create([
            'storage_file_id' => $this->storageFile()->id,
            'document_type_id' => $type->id,
            'document_group_id' => (string) Str::uuid(),
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
            'client_filename' => 'b.pdf',
            'is_latest' => true,
            'latest_marker' => $type->id,
        ]));
    }

    public function test_a_lost_race_is_retried_as_a_new_version_of_the_winner(): void
    {
        $type = $this->singleSlotType(versioned: true);
        $owner = $this->owner();
        $winner = $this->latestDocument($type, $owner, marker: $type->id);

        // The first read is stale and sees an empty slot, as it would if the winner committed a moment later.
        $this->app->bind(DocumentRepository::class, fn () => new class extends DocumentRepository
        {
            private bool $stale = true;

            public function findLatest(string $documentableType, string $documentableId, string $documentTypeId, ?string $documentGroupId = null, bool $lock = false): ?Document
            {
                if ($this->stale) {
                    $this->stale = false;

                    return null;
                }

                return parent::findLatest($documentableType, $documentableId, $documentTypeId, $documentGroupId, $lock);
            }
        });

        $document = $this->attacher()->attachStorageFile($this->storageFile(), $type, $owner, 'loser.pdf');

        $this->assertSame(2, $document->version);
        $this->assertSame($winner->document_group_id, $document->document_group_id);
        $this->assertFalse($winner->refresh()->is_latest);
        $this->assertSame(1, Document::where('document_type_id', $type->id)->whereNotNull('latest_marker')->count());
    }

    public function test_a_conflict_that_survives_the_retry_surfaces_as_a_typed_exception(): void
    {
        $type = $this->singleSlotType();
        $owner = $this->owner();
        $this->latestDocument($type, $owner, marker: $type->id);

        // Every read claims the slot is empty, so the write collides every time.
        $this->app->bind(DocumentRepository::class, fn () => new class extends DocumentRepository
        {
            public function findLatest(string $documentableType, string $documentableId, string $documentTypeId, ?string $documentGroupId = null, bool $lock = false): ?Document
            {
                return null;
            }
        });

        $this->expectException(SlotConflict::class);

        $this->attacher()->attachStorageFile($this->storageFile(), $type, $owner, 'loser.pdf');
    }

    private function attacher(): DocumentAttacher
    {
        return $this->app->make(DocumentAttacher::class);
    }
}
