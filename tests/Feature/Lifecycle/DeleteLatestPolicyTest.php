<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Enums\OnDeleteLatest;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentVersionPromoted;
use MadeByClowd\Documentable\Exceptions\CannotDeleteLatestVersion;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class DeleteLatestPolicyTest extends TestCase
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
            'on_delete_latest' => OnDeleteLatest::Promote,
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

    public function test_promote_policy_promotes_highest_previous_version_when_deleting_latest(): void
    {
        Event::fake([DocumentDeleted::class, DocumentVersionPromoted::class]);

        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::Promote]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id);

        $this->assertSame(3, $v3->version);
        $this->assertTrue($v3->is_latest);

        $this->service->delete($v3);

        $this->assertSoftDeleted('documents', ['id' => $v3->id]);
        $this->assertNull($v3->fresh()->latest_marker);
        $this->assertFalse($v3->fresh()->is_latest);

        // v2 is promoted
        $this->assertTrue($v2->fresh()->is_latest);
        $this->assertSame($v1->document_group_id, $v2->fresh()->latest_marker);

        Event::assertDispatched(DocumentDeleted::class, fn (DocumentDeleted $e) => $e->document->id === $v3->id);
        Event::assertDispatched(DocumentVersionPromoted::class, fn (DocumentVersionPromoted $e) => $e->promoted->id === $v2->id && $e->previous?->id === $v3->id);
    }

    public function test_promote_policy_with_only_one_version_deletes_without_promotion(): void
    {
        Event::fake([DocumentDeleted::class, DocumentVersionPromoted::class]);

        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::Promote]);
        $v1 = $this->uploadVersion($type, 'v1');

        $this->service->delete($v1);

        $this->assertSoftDeleted('documents', ['id' => $v1->id]);
        $this->assertNull($v1->fresh()->latest_marker);
        $this->assertFalse($v1->fresh()->is_latest);

        Event::assertDispatched(DocumentDeleted::class);
        Event::assertNotDispatched(DocumentVersionPromoted::class);
    }

    public function test_promote_policy_skips_soft_deleted_and_rejected_versions(): void
    {
        Event::fake([DocumentDeleted::class, DocumentVersionPromoted::class]);

        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::Promote]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id);
        $v4 = $this->uploadVersion($type, 'v4', $v1->document_group_id);

        // Soft-delete v3 (non-latest delete)
        $this->service->delete($v3);

        // Mark v2 as rejected
        $v2->update(['status' => DocumentStatus::Rejected]);

        // Now delete latest (v4) -> should skip v3 (trashed) and v2 (rejected) and promote v1!
        $this->service->delete($v4);

        $this->assertTrue($v1->fresh()->is_latest);
        $this->assertSame($v1->document_group_id, $v1->fresh()->latest_marker);

        Event::assertDispatched(DocumentVersionPromoted::class, fn (DocumentVersionPromoted $e) => $e->promoted->id === $v1->id && $e->previous?->id === $v4->id);
    }

    public function test_promote_policy_for_single_slot_type_sets_type_id_marker(): void
    {
        $type = $this->makeType([
            'allows_multiple' => false,
            'on_delete_latest' => OnDeleteLatest::Promote,
        ]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2');

        $this->assertSame($type->id, $v2->latest_marker);

        $this->service->delete($v2);

        $this->assertTrue($v1->fresh()->is_latest);
        $this->assertSame($type->id, $v1->fresh()->latest_marker);
    }

    public function test_none_policy_clears_marker_and_does_not_promote(): void
    {
        Event::fake([DocumentDeleted::class, DocumentVersionPromoted::class]);

        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::None]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        $this->service->delete($v2);

        $this->assertSoftDeleted('documents', ['id' => $v2->id]);
        $this->assertNull($v2->fresh()->latest_marker);
        $this->assertFalse($v2->fresh()->is_latest);

        $this->assertFalse($v1->fresh()->is_latest);
        $this->assertNull($v1->fresh()->latest_marker);

        Event::assertDispatched(DocumentDeleted::class);
        Event::assertNotDispatched(DocumentVersionPromoted::class);
    }

    public function test_none_policy_with_single_version_deletes(): void
    {
        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::None]);
        $v1 = $this->uploadVersion($type, 'v1');

        $this->service->delete($v1);

        $this->assertSoftDeleted('documents', ['id' => $v1->id]);
        $this->assertNull($v1->fresh()->latest_marker);
    }

    public function test_block_policy_throws_cannot_delete_latest_version_when_older_versions_exist(): void
    {
        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::Block]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        $this->expectException(CannotDeleteLatestVersion::class);

        try {
            $this->service->delete($v2);
        } finally {
            // Assert nothing was changed
            $this->assertNotSoftDeleted('documents', ['id' => $v2->id]);
            $this->assertTrue($v2->fresh()->is_latest);
            $this->assertNotNull($v2->fresh()->latest_marker);
            $this->assertFalse($v1->fresh()->is_latest);
        }
    }

    public function test_block_policy_allows_deleting_latest_when_it_is_the_only_version(): void
    {
        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::Block]);

        $v1 = $this->uploadVersion($type, 'v1');

        $this->service->delete($v1);

        $this->assertSoftDeleted('documents', ['id' => $v1->id]);
        $this->assertNull($v1->fresh()->latest_marker);
    }

    public function test_deleting_non_latest_version_is_unchanged_across_all_policies(): void
    {
        Event::fake([DocumentDeleted::class, DocumentVersionPromoted::class]);

        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::Block]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        // v1 is non-latest, deleting it should succeed even with block policy
        $this->service->delete($v1);

        $this->assertSoftDeleted('documents', ['id' => $v1->id]);
        $this->assertTrue($v2->fresh()->is_latest);
        Event::assertDispatched(DocumentDeleted::class);
        Event::assertNotDispatched(DocumentVersionPromoted::class);
    }

    public function test_concurrent_delete_and_upload_maintains_exactly_one_latest_and_marker(): void
    {
        $type = $this->makeType(['on_delete_latest' => OnDeleteLatest::Promote]);

        $v1 = $this->uploadVersion($type, 'v1');
        $v2 = $this->uploadVersion($type, 'v2', $v1->document_group_id);

        // Simulate interleaving: delete v2 and upload v3
        $this->service->delete($v2);
        $v3 = $this->uploadVersion($type, 'v3', $v1->document_group_id);

        $report = app(IntegrityInspector::class)->inspect();
        $this->assertEmpty($report->duplicateSlots);

        $latestCount = Document::where('document_group_id', $v1->document_group_id)
            ->where('is_latest', true)
            ->count();
        $this->assertSame(1, $latestCount);
        $this->assertSame($v3->id, Document::where('document_group_id', $v1->document_group_id)->where('is_latest', true)->first()->id);
    }
}
