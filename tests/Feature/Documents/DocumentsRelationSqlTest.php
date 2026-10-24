<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Relations\DocumentsMorphMany;
use MadeByClowd\Documentable\Tests\Fixtures\StringKeyTestModel;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The SQL the relation generates per database driver. Building a query never opens a connection, so these run
 * on every leg and pin the driver-specific cast that the single live engine of a CI leg cannot exercise.
 */
class DocumentsRelationSqlTest extends TestCase
{
    protected function useDriver(string $driver): void
    {
        config()->set('database.connections.offline', ['driver' => $driver, 'database' => 'offline', 'host' => '127.0.0.1', 'prefix' => '']);
        config()->set('database.default', 'offline');

        // Testbench migrates and rolls back against the default connection around each test.
        $this->beforeApplicationDestroyed(fn () => config()->set('database.default', 'testing'));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function drivers(): array
    {
        return [
            'pgsql casts to varchar' => ['pgsql', 'cast("test_models"."id" as varchar)'],
            'sqlsrv casts to nvarchar' => ['sqlsrv', 'cast([test_models].[id] as nvarchar(255))'],
            'mysql compares as is' => ['mysql', null],
            'sqlite compares as is' => ['sqlite', null],
        ];
    }

    #[DataProvider('drivers')]
    public function test_existence_query_casts_an_int_key_only_where_the_driver_needs_it(string $driver, ?string $cast): void
    {
        $this->useDriver($driver);

        $sql = strtolower(TestModel::whereHas('documents')->toSql());

        if ($cast === null) {
            $this->assertStringNotContainsString('cast(', $sql);
        } else {
            $this->assertStringContainsString($cast, $sql);
        }
    }

    public function test_a_string_key_owner_is_never_cast(): void
    {
        $this->useDriver('pgsql');

        $this->assertStringNotContainsString('cast(', strtolower(StringKeyTestModel::whereHas('documents')->toSql()));
    }

    public function test_the_existence_query_stays_scoped_to_the_owner_type(): void
    {
        $this->useDriver('pgsql');

        $query = TestModel::whereHas('documents');

        $this->assertContains('test_model', $query->getBindings());
    }

    public function test_an_unsaved_owner_has_no_parent_key(): void
    {
        $relation = (new TestModel)->documents();

        $this->assertInstanceOf(DocumentsMorphMany::class, $relation);
        $this->assertNull($relation->getParentKey());
    }

    public function test_a_self_relation_falls_back_to_the_stock_aliased_query(): void
    {
        $relation = new DocumentsMorphMany(Document::query(), new Document, 'documents.documentable_type', 'documents.documentable_id', 'id');

        $query = $relation->getRelationExistenceQuery(Document::query(), Document::query());

        $this->assertStringContainsString(' as ', strtolower($query->toSql()));
    }
}
