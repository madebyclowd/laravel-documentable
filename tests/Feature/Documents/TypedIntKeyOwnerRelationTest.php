<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Relations\DocumentsMorphMany;
use MadeByClowd\Documentable\Tests\Fixtures\StringKeyTestModel;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;

/**
 * The same relation suite, run against an install whose `documentable_id` column was converted to an integer.
 * Laravel's stock `MorphMany` then works on every engine with no cast, which is the point of the typed key.
 * On PostgreSQL this is the leg that would fail if the conversion left the column a string.
 */
class TypedIntKeyOwnerRelationTest extends IntKeyOwnerRelationTest
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('documentable.key_type', 'int');
        $this->app->forgetInstance(DocumentableConfig::class);
        (require dirname(__DIR__, 3).'/database/migrations/2026_10_27_000013_type_documentable_id.php')->up();
    }

    public function test_a_typed_key_uses_the_stock_relation(): void
    {
        $relation = TestModel::create(['name' => 'one'])->documents();

        $this->assertInstanceOf(MorphMany::class, $relation);
        $this->assertNotInstanceOf(DocumentsMorphMany::class, $relation);
    }

    public function test_documents_can_be_created_through_the_relation_for_an_int_key_owner(): void
    {
        $owner = TestModel::create(['name' => 'one']);
        $this->attach($owner);
        $existing = $owner->documents()->first();

        $copy = $owner->documents()->make(['storage_file_id' => $existing->storage_file_id, 'client_filename' => 'x.txt', 'document_type_id' => $this->type->id]);

        $this->assertSame($owner->getKey(), (int) $copy->documentable_id);
    }

    public function test_the_relation_only_sees_documents_of_its_own_owner_type(): void
    {
        $owner = TestModel::create(['name' => 'one']);
        $other = StringKeyTestModel::create(['id' => (string) $owner->getKey(), 'name' => 'same key, other type']);
        $this->attach($owner);
        $this->attach($other, 'b.txt');

        $this->assertCount(1, $owner->fresh()->documents);
        $this->assertCount(1, $other->fresh()->documents);
    }

    public function test_a_string_key_owner_keeps_working(): void
    {
        $this->markTestSkipped('An integer owner key column cannot hold a string key; that is what the default `string` key type is for.');
    }
}
