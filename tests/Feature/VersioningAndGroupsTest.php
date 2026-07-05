<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class VersioningAndGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'ATTACHMENT',
            'name' => 'Attachment',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'uploads',
            'allows_multiple' => true,
            'requires_versioning' => true,
        ], $overrides));
    }

    public function test_independent_groups_version_independently(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        // Two independent slots under the same owner+type.
        $groupA1 = $this->service->upload(UploadedFile::fake()->createWithContent('a1.txt', 'a1'), $type, $owner);
        $groupB1 = $this->service->upload(UploadedFile::fake()->createWithContent('b1.txt', 'b1'), $type, $owner);

        $this->assertNotSame($groupA1->document_group_id, $groupB1->document_group_id);

        // New version of group A only — group B untouched.
        $groupA2 = $this->service->upload(
            UploadedFile::fake()->createWithContent('a2.txt', 'a2'),
            $type,
            $owner,
            [],
            $groupA1->document_group_id
        );

        $this->assertSame($groupA1->document_group_id, $groupA2->document_group_id);
        $this->assertSame(2, $groupA2->version);
        $this->assertTrue($groupA2->is_latest);

        $this->assertFalse($groupA1->fresh()->is_latest);
        $this->assertTrue($groupB1->fresh()->is_latest);
        $this->assertSame(1, $groupB1->fresh()->version);
    }

    public function test_soft_deleting_group_latest_allows_new_version_without_unique_violation(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $doc = $this->service->upload(UploadedFile::fake()->createWithContent('v1.txt', 'v1'), $type, $owner);
        $groupId = $doc->document_group_id;

        $this->service->delete($doc->fresh());

        $this->assertNull($doc->fresh()->latest_marker);

        // This would previously violate the Postgres/SQLite partial-index
        // equivalent (bugs.md #1) — the nullable latest_marker column fixes it.
        $newLatest = $this->service->upload(
            UploadedFile::fake()->createWithContent('v2.txt', 'v2'),
            $type,
            $owner,
            [],
            $groupId
        );

        $this->assertSame($groupId, $newLatest->document_group_id);
        $this->assertTrue($newLatest->is_latest);
        $this->assertSame($groupId, $newLatest->latest_marker);
    }

    public function test_find_all_latest_for_owner_returns_one_row_per_group(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $groupA1 = $this->service->upload(UploadedFile::fake()->createWithContent('a1.txt', 'a1'), $type, $owner);
        $this->service->upload(UploadedFile::fake()->createWithContent('a2.txt', 'a2'), $type, $owner, [], $groupA1->document_group_id);
        $this->service->upload(UploadedFile::fake()->createWithContent('b1.txt', 'b1'), $type, $owner);

        $repository = $this->app->make(DocumentRepository::class);

        $latest = $repository->findAllLatestForOwner($owner->getMorphClass(), (string) $owner->getKey(), $type->id);

        $this->assertCount(2, $latest);
    }

    public function test_find_version_history_returns_full_ordered_chain_including_soft_deleted(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $v1 = $this->service->upload(UploadedFile::fake()->createWithContent('v1.txt', 'v1'), $type, $owner);
        $groupId = $v1->document_group_id;
        $this->service->upload(UploadedFile::fake()->createWithContent('v2.txt', 'v2'), $type, $owner, [], $groupId);
        $this->service->upload(UploadedFile::fake()->createWithContent('v3.txt', 'v3'), $type, $owner, [], $groupId);

        $repository = $this->app->make(DocumentRepository::class);

        $history = $repository->findVersionHistory($groupId, withTrashed: true);

        $this->assertCount(3, $history);
        $this->assertSame([1, 2, 3], $history->pluck('version')->all());
        $this->assertTrue($history->last()->is_latest);
    }

    public function test_single_slot_type_still_resolves_one_group_across_replacements(): void
    {
        $type = $this->makeType(['allows_multiple' => false, 'requires_versioning' => false]);
        $owner = TestModel::create(['name' => 'owner']);

        $first = $this->service->upload(UploadedFile::fake()->createWithContent('v1.txt', 'v1'), $type, $owner);
        $second = $this->service->upload(UploadedFile::fake()->createWithContent('v2.txt', 'v2'), $type, $owner);

        $this->assertSame(1, Document::count());
        $this->assertSame('v2.txt', Document::first()->client_filename);
        $this->assertNotNull($second->document_group_id);
        $this->assertTrue($second->is_latest);
    }
}
