<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Tests\TestCase;

class WidenMultipartColumnsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Upload sessions replaced `multipart_uploads`, so a fresh install drops it again. These tests are about
     * the table an upgrading app still has, so each one rebuilds it the way the original migrations left it.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('multipart_uploads');
        (require __DIR__.'/../../../database/migrations/2026_07_05_000004_create_multipart_uploads_table.php')->up();
        $this->migration()->up();
    }

    protected function migration(): object
    {
        return require __DIR__.'/../../../database/migrations/2026_10_24_000006_widen_multipart_uploads_columns.php';
    }

    /**
     * @return array<string, string>
     */
    protected function columnTypes(): array
    {
        return collect(Schema::getColumns('multipart_uploads'))->pluck('type_name', 'name')->all();
    }

    /**
     * @return list<list<string>>
     */
    protected function indexColumns(): array
    {
        return collect(Schema::getIndexes('multipart_uploads'))
            ->reject(fn (array $index) => $index['primary'])
            ->map(fn (array $index) => $index['columns'])
            ->values()
            ->all();
    }

    public function test_path_and_upload_id_become_text_and_the_composite_index_is_replaced_by_a_user_index(): void
    {
        $types = $this->columnTypes();
        $this->assertSame('text', $types['path']);
        $this->assertSame('text', $types['upload_id']);

        $indexes = $this->indexColumns();
        $this->assertContains(['user_id'], $indexes);
        $this->assertNotContains(['path', 'upload_id', 'user_id'], $indexes);
    }

    public function test_running_the_migration_again_changes_nothing(): void
    {
        $before = [$this->columnTypes(), $this->indexColumns()];

        $this->migration()->up();

        $this->assertSame($before, [$this->columnTypes(), $this->indexColumns()]);
    }

    public function test_a_table_a_consumer_already_widened_and_re_indexed_is_left_alone(): void
    {
        Schema::table('multipart_uploads', function ($table) {
            $table->dropIndex(['user_id']);
        });
        Schema::table('multipart_uploads', function ($table) {
            $table->index(['user_id', 'expires_at'], 'consumer_own_index');
        });

        $this->migration()->up();

        $this->assertSame('text', $this->columnTypes()['path']);
        $this->assertContains(['user_id', 'expires_at'], $this->indexColumns());
        $this->assertContains(['user_id'], $this->indexColumns());
    }

    public function test_storage_file_path_is_text_and_its_migration_is_idempotent(): void
    {
        $type = fn () => collect(Schema::getColumns('storage_files'))->firstWhere('name', 'path')['type_name'];

        $this->assertSame('text', $type());

        (require __DIR__.'/../../../database/migrations/2026_10_24_000007_widen_storage_files_path.php')->up();

        $this->assertSame('text', $type());
    }

    public function test_a_table_still_in_its_original_shape_is_upgraded_and_keeps_its_rows(): void
    {
        Schema::table('multipart_uploads', fn ($table) => $table->dropIndex(['user_id']));
        Schema::table('multipart_uploads', function ($table) {
            $table->string('path')->change();
            $table->string('upload_id')->change();
        });
        Schema::table('multipart_uploads', fn ($table) => $table->index(['path', 'upload_id', 'user_id']));

        $type = DocumentType::create([
            'code' => 'BIG', 'name' => 'Big', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'docs',
        ]);
        DB::table('multipart_uploads')->insert([
            'id' => 'legacy-1', 'path' => 'docs/abc', 'upload_id' => 'upload-1', 'user_id' => 'user-1', 'document_type_id' => $type->id,
        ]);

        $this->migration()->up();

        $this->assertSame('text', $this->columnTypes()['path']);
        $this->assertSame('text', $this->columnTypes()['upload_id']);
        $this->assertContains(['user_id'], $this->indexColumns());
        $this->assertNotContains(['path', 'upload_id', 'user_id'], $this->indexColumns());
        $this->assertSame('upload-1', DB::table('multipart_uploads')->where('id', 'legacy-1')->value('upload_id'));
    }
}
