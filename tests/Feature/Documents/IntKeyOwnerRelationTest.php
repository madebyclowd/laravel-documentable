<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\StringKeyTestModel;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * `documents.documentable_id` is a string column so one table can serve owners with int, uuid and ulid keys.
 * On PostgreSQL that made every relation query against an owner with an integer primary key fail with
 * `operator does not exist: character varying = integer` (lazy loads bind an int, eager loads inline unquoted
 * integers, and `whereHas`/`withCount` compare the two columns directly). SQLite and MySQL coerce silently,
 * so only the engine matrix catches a regression.
 */
class IntKeyOwnerRelationTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentType $type;

    protected DocumentService $service;

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

    protected function attach(Model $owner, string $name = 'a.txt'): void
    {
        $this->service->upload(UploadedFile::fake()->createWithContent($name, uniqid('', true)), $this->type, $owner);
    }

    public function test_lazy_loading_documents_of_an_int_key_owner(): void
    {
        $owner = TestModel::create(['name' => 'one']);
        $this->attach($owner);
        $this->attach($owner, 'b.txt');

        $this->assertIsInt($owner->getKey());
        $this->assertCount(2, $owner->fresh()->documents);
    }

    public function test_eager_loading_documents_for_several_int_key_owners(): void
    {
        $first = TestModel::create(['name' => 'one']);
        $second = TestModel::create(['name' => 'two']);
        TestModel::create(['name' => 'three']);
        $this->attach($first);
        $this->attach($first, 'b.txt');
        $this->attach($second, 'c.txt');

        $owners = TestModel::with('documents')->orderBy('id')->get();

        $this->assertSame([2, 1, 0], $owners->map(fn (TestModel $owner) => $owner->documents->count())->all());
    }

    public function test_where_has_filters_int_key_owners_by_their_documents(): void
    {
        $withDocument = TestModel::create(['name' => 'one']);
        TestModel::create(['name' => 'two']);
        $this->attach($withDocument);

        $owners = TestModel::whereHas('documents', fn ($query) => $query->where('is_latest', true))->get();

        $this->assertSame([$withDocument->getKey()], $owners->modelKeys());
    }

    public function test_where_doesnt_have_returns_the_owners_without_documents(): void
    {
        $withDocument = TestModel::create(['name' => 'one']);
        $without = TestModel::create(['name' => 'two']);
        $this->attach($withDocument);

        $this->assertSame([$without->getKey()], TestModel::whereDoesntHave('documents')->get()->modelKeys());
    }

    public function test_with_count_counts_documents_per_int_key_owner(): void
    {
        $first = TestModel::create(['name' => 'one']);
        $second = TestModel::create(['name' => 'two']);
        $this->attach($first);
        $this->attach($first, 'b.txt');
        $this->attach($second, 'c.txt');

        $counts = TestModel::withCount('documents')->orderBy('id')->pluck('documents_count')->all();

        $this->assertSame([2, 1], array_map('intval', $counts));
    }

    public function test_the_relation_only_sees_documents_of_its_own_owner_type(): void
    {
        $owner = TestModel::create(['name' => 'one']);
        $other = StringKeyTestModel::create(['id' => (string) $owner->getKey(), 'name' => 'same key, other type']);
        $this->attach($owner);
        $this->attach($other, 'b.txt');

        $this->assertCount(1, $owner->fresh()->documents);
        $this->assertCount(1, $other->fresh()->documents);
        $this->assertSame([$owner->getKey()], TestModel::whereHas('documents')->get()->modelKeys());
    }

    public function test_documents_can_be_created_through_the_relation_for_an_int_key_owner(): void
    {
        $owner = TestModel::create(['name' => 'one']);
        $this->attach($owner);
        $existing = $owner->documents()->first();

        $copy = $owner->documents()->make(['storage_file_id' => $existing->storage_file_id, 'client_filename' => 'x.txt', 'document_type_id' => $this->type->id]);

        $this->assertSame((string) $owner->getKey(), $copy->documentable_id);
    }

    public function test_a_string_key_owner_keeps_working(): void
    {
        $owner = StringKeyTestModel::create(['id' => 'owner-a', 'name' => 'a']);
        StringKeyTestModel::create(['id' => 'owner-b', 'name' => 'b']);
        $this->attach($owner);

        $this->assertCount(1, $owner->fresh()->documents);
        $this->assertSame(['owner-a'], StringKeyTestModel::whereHas('documents')->get()->modelKeys());
        $this->assertSame(1, StringKeyTestModel::with('documents')->find('owner-a')->documents->count());
    }
}
