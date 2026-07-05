<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/feedback.md #3 — resolveDocumentable()
 * used to fall back to any Eloquent-model FQCN when unmapped. See
 * docs/implementations/v2.0.0/phase-9-documentable-type-allowlist.md.
 */
class DocumentableTypeAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
    }

    protected function makeType(): DocumentType
    {
        return DocumentType::create([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
        ]);
    }

    protected function upload(string $documentableType, TestModel $owner, DocumentType $type): TestResponse
    {
        return $this->postJson('/documents', [
            'file' => UploadedFile::fake()->create('a.txt', 1),
            'document_type_id' => $type->id,
            'documentable_type' => $documentableType,
            'documentable_id' => (string) $owner->getKey(),
        ]);
    }

    public function test_morph_mapped_type_resolves_regardless_of_allowlist(): void
    {
        $owner = TestModel::create(['name' => 'owner']);
        $type = $this->makeType();

        config()->set('documentable.security.allowed_documentable_types', null);
        $this->upload($owner->getMorphClass(), $owner, $type)->assertCreated();

        config()->set('documentable.security.allowed_documentable_types', ['something/unrelated']);
        $this->upload($owner->getMorphClass(), $owner, $type)->assertCreated();

        $this->assertSame(2, Document::withTrashed()->count());
    }

    public function test_unmapped_raw_fqcn_rejected_when_allowlist_is_null(): void
    {
        $owner = TestModel::create(['name' => 'owner']);
        $type = $this->makeType();

        config()->set('documentable.security.allowed_documentable_types', null);

        $response = $this->upload(TestModel::class, $owner, $type);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['documentable_type']);
    }

    public function test_unmapped_raw_fqcn_resolves_when_present_in_allowlist(): void
    {
        $owner = TestModel::create(['name' => 'owner']);
        $type = $this->makeType();

        config()->set('documentable.security.allowed_documentable_types', [TestModel::class]);

        $this->upload(TestModel::class, $owner, $type)->assertCreated();
    }

    public function test_unmapped_raw_fqcn_rejected_when_absent_from_non_empty_allowlist(): void
    {
        $owner = TestModel::create(['name' => 'owner']);
        $type = $this->makeType();

        config()->set('documentable.security.allowed_documentable_types', ['App\\Models\\SomethingElse']);

        $response = $this->upload(TestModel::class, $owner, $type);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['documentable_type']);
    }
}
