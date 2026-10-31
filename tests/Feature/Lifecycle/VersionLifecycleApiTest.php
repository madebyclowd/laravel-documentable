<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentVersionPromoted;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class VersionLifecycleApiTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        $this->service = $this->app->make(DocumentService::class);
        $this->owner = TestModel::create(['name' => 'Owner']);
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'DOC',
            'name' => 'Document',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'docs',
            'allows_multiple' => true,
            'requires_versioning' => true,
        ], $overrides));
    }

    protected function uploadVersion(DocumentType $type, string $content, ?string $groupId = null): Document
    {
        return $this->service->upload(
            UploadedFile::fake()->createWithContent("{$content}.txt", $content),
            $type,
            $this->owner,
            [],
            $groupId
        );
    }

    protected function assertIntegrityClean(): void
    {
        $report = app(IntegrityInspector::class)->inspect();
        $this->assertEmpty($report->duplicateSlots);
    }

    // --- restore() tests ---

    public function test_restore_non_trashed_document_is_a_noop(): void
    {
        $type = $this->makeType();
        $doc = $this->uploadVersion($type, 'v1');

        $result = $this->service->restore($doc);

        $this->assertSame($doc->id, $result->id);
        $this->assertFalse($result->trashed());
        $this->assertTrue($result->is_latest);
        $this->assertIntegrityClean();
    }

    public function test_restore_when_group_has_no_latest_and_it_is_highest_version_becomes_latest(): void
    {
        $type = $this->makeType();
        $v1 = $this->uploadVersion($type, 'v1');

        // Delete v1
        $this->service->delete($v1);
        $this->assertTrue($v1->fresh()->trashed());

        // Restore v1
        $restored = $this->service->restore($v1);

        $this->assertFalse($restored->fresh()->trashed());
        $this->assertTrue($restored->fresh()->is_latest);
        $this->assertSame($v1->document_group_id, $restored->fresh()->latest_marker);
        $this->assertIntegrityClean();
    }

    public function test_restore_when_group_currently_has_a_latest_comes_back_as_non_latest(): void
    {
        $type = $this->makeType();
        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        // Delete non-latest v1
        $this->service->delete($v1);
        $this->assertTrue($v1->fresh()->trashed());
        $this->assertTrue($v2->fresh()->is_latest);

        // Restore v1
        $restored = $this->service->restore($v1);

        $this->assertFalse($restored->fresh()->trashed());
        $this->assertFalse($restored->fresh()->is_latest);
        $this->assertNull($restored->fresh()->latest_marker);
        $this->assertTrue($v2->fresh()->is_latest);
        $this->assertIntegrityClean();
    }

    public function test_restore_when_group_has_no_latest_but_another_active_version_is_higher(): void
    {
        $type = $this->makeType();
        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        // Soft-delete v1 first
        $this->service->delete($v1);

        // Force clear marker on v2 without deleting it, so group has no latest
        $v2->update(['is_latest' => false, 'latest_marker' => null]);

        // Restoring v1 should NOT make it latest because v2 is active and has higher version
        $restored = $this->service->restore($v1);

        $this->assertFalse($restored->fresh()->trashed());
        $this->assertFalse($restored->fresh()->is_latest);
        $this->assertNull($restored->fresh()->latest_marker);
        $this->assertIntegrityClean();
    }

    public function test_restore_does_not_violate_unique_slot_for_single_slot_type(): void
    {
        $type = $this->makeType(['allows_multiple' => false]);
        $v1 = $this->uploadVersion($type, 'v1');
        $this->service->delete($v1);

        // Upload another document that occupies the single slot
        $v2 = $this->uploadVersion($type, 'v2');
        $this->assertSame($type->id, $v2->latest_marker);

        // Restoring v1 must not crash with duplicate key and must come back non-latest
        $restored = $this->service->restore($v1);

        $this->assertFalse($restored->fresh()->trashed());
        $this->assertFalse($restored->fresh()->is_latest);
        $this->assertNull($restored->fresh()->latest_marker);
        $this->assertSame($type->id, $v2->fresh()->latest_marker);
        $this->assertIntegrityClean();
    }

    // --- deleteGroup() tests ---

    public function test_delete_group_soft_deletes_all_versions_and_clears_markers(): void
    {
        Event::fake([DocumentDeleted::class, DocumentVersionPromoted::class]);

        $type = $this->makeType();
        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id);

        $count = $this->service->deleteGroup($v1->document_group_id);

        $this->assertSame(3, $count);
        $this->assertSoftDeleted('documents', ['id' => $v1->id]);
        $this->assertSoftDeleted('documents', ['id' => $v2->id]);
        $this->assertSoftDeleted('documents', ['id' => $v3->id]);

        $this->assertNull($v1->fresh()->latest_marker);
        $this->assertNull($v2->fresh()->latest_marker);
        $this->assertNull($v3->fresh()->latest_marker);

        $this->assertFalse($v1->fresh()->is_latest);
        $this->assertFalse($v2->fresh()->is_latest);
        $this->assertFalse($v3->fresh()->is_latest);

        Event::assertDispatched(DocumentDeleted::class, 3);
        Event::assertNotDispatched(DocumentVersionPromoted::class);
        $this->assertIntegrityClean();
    }

    public function test_delete_group_returns_zero_for_unknown_group(): void
    {
        $count = $this->service->deleteGroup('non-existent-group');
        $this->assertSame(0, $count);

        $uuidCount = $this->service->deleteGroup((string) Str::uuid());
        $this->assertSame(0, $uuidCount);
    }

    // --- promote() tests ---

    public function test_promote_makes_previous_version_latest_and_unsets_previous_latest(): void
    {
        Event::fake([DocumentVersionPromoted::class]);

        $type = $this->makeType();
        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        $this->assertTrue($v2->is_latest);
        $this->assertFalse($v1->fresh()->is_latest);

        $promoted = $this->service->promote($v1);

        $this->assertSame($v1->id, $promoted->id);
        $this->assertTrue($v1->fresh()->is_latest);
        $this->assertSame($v1->document_group_id, $v1->fresh()->latest_marker);

        $this->assertFalse($v2->fresh()->is_latest);
        $this->assertNull($v2->fresh()->latest_marker);

        Event::assertDispatched(DocumentVersionPromoted::class, fn (DocumentVersionPromoted $e) => $e->promoted->id === $v1->id && $e->previous?->id === $v2->id);
        $this->assertIntegrityClean();
    }

    public function test_promote_current_latest_is_a_noop(): void
    {
        Event::fake([DocumentVersionPromoted::class]);

        $type = $this->makeType();
        $v1 = $this->uploadVersion($type, 'v1');

        $result = $this->service->promote($v1);

        $this->assertSame($v1->id, $result->id);
        $this->assertTrue($result->is_latest);
        Event::assertNotDispatched(DocumentVersionPromoted::class);
        $this->assertIntegrityClean();
    }

    public function test_promote_trashed_document_throws_invalid_argument_exception(): void
    {
        $type = $this->makeType();
        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        $this->service->delete($v1);

        $this->expectException(InvalidArgumentException::class);
        $this->service->promote($v1);
    }
}
