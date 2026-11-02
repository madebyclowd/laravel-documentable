<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\ConfigurableAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * `GET /documents/{document}` is how a client waits for a `processing` document: it reads the status until it
 * turns `committed` or `rejected`.
 */
class DocumentShowEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Document $document;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test_disk');
        $type = DocumentType::create([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
        ]);
        $this->document = $this->app->make(DocumentService::class)->upload(
            UploadedFile::fake()->createWithContent('a.txt', 'hello'),
            $type,
            TestModel::create(['name' => 'Owner']),
        );
    }

    public function test_it_returns_the_document_with_its_status(): void
    {
        $this->getJson('/documents/'.$this->document->id)
            ->assertOk()
            ->assertJsonPath('id', $this->document->id)
            ->assertJsonPath('status', DocumentStatus::Committed->value)
            ->assertJsonPath('document_group_id', $this->document->document_group_id);
    }

    public function test_a_processing_document_reports_that(): void
    {
        $this->document->forceFill(['status' => DocumentStatus::Processing])->save();

        $this->getJson('/documents/'.$this->document->id)->assertOk()->assertJsonPath('status', 'processing');
    }

    public function test_the_authorizer_decides_who_may_see_it(): void
    {
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canView'));

        $this->getJson('/documents/'.$this->document->id)->assertForbidden();
    }

    public function test_an_unknown_document_is_not_found(): void
    {
        $this->getJson('/documents/00000000-0000-0000-0000-000000000000')->assertNotFound();
    }

    public function test_it_does_not_shadow_the_literal_routes(): void
    {
        $this->getJson('/documents/types')->assertOk();
        $this->getJson('/documents/config')->assertOk()->assertJsonStructure(['threshold_bytes']);
    }
}
