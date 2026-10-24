<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class FinalizeRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    private const SHAPE_MESSAGE = 'The path must be a relative storage key without ".." segments, backslashes or control characters.';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
    }

    private function finalizePayload(string $path, array $overrides = []): array
    {
        $type = DocumentType::firstOrCreate(['code' => 'DOC'], [
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
        ]);
        $owner = TestModel::firstOrCreate(['name' => 'owner']);

        return array_merge([
            'path' => $path,
            'document_type_id' => $type->id,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
            'filename' => 'file.bin',
        ], $overrides);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafePaths(): array
    {
        return [
            'parent traversal' => ['docs/../../etc/passwd'],
            'leading parent traversal' => ['../secret.bin'],
            'absolute path' => ['/docs/file.bin'],
            'backslash separator' => ['docs\\file.bin'],
            'longer than an S3 key' => [str_repeat('a', 1025)],
        ];
    }

    #[DataProvider('unsafePaths')]
    public function test_finalize_rejects_an_unsafe_path_before_touching_storage(string $path): void
    {
        $this->postJson('/documents/presigned/finalize', $this->finalizePayload($path))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path' => self::SHAPE_MESSAGE]);
    }

    public function test_finalize_accepts_a_well_formed_path_and_reports_a_missing_object_instead(): void
    {
        $this->postJson('/documents/presigned/finalize', $this->finalizePayload('docs/2026/10/3f2a-uuid.bin'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['path' => 'No file found at the presigned upload path.']);
    }

    public function test_finalize_with_a_wrong_hash_over_http_returns_422_and_keeps_the_object(): void
    {
        Storage::disk('test_disk')->put('other-tenant/report.pdf', 'someone elses file');

        $this->postJson('/documents/presigned/finalize', $this->finalizePayload('other-tenant/report.pdf', [
            'expected_hash' => hash('sha256', 'different bytes'),
        ]))->assertUnprocessable();

        $this->assertTrue(Storage::disk('test_disk')->exists('other-tenant/report.pdf'));
    }
}
