<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/v2.0.0-feedback.md #3 — no HTTP endpoint to list
 * document types, forcing every consumer to hand-roll access to document_type_id. See
 * docs/implementations/v2.1.0/phase-16-document-types-listing-endpoint.md.
 */
class DocumentTypeListingEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'DOC-'.uniqid(),
            'name' => 'Doc',
            'max_size_mb' => 10,
            'allowed_mimes' => ['application/pdf'],
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'allows_multiple' => false,
            'requires_versioning' => true,
        ], $overrides));
    }

    public function test_returns_all_active_types_with_expected_fields(): void
    {
        $this->makeType(['code' => 'invoice', 'name' => 'Invoice']);
        $this->makeType(['code' => 'contract', 'name' => 'Contract']);

        $response = $this->getJson('/documents/types');

        $response->assertOk();
        $codes = collect($response->json())->pluck('code')->all();
        $this->assertEqualsCanonicalizing(['invoice', 'contract'], $codes);

        $first = $response->json()[0];
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('max_size_mb', $first);
        $this->assertArrayHasKey('allowed_mimes', $first);
        $this->assertArrayHasKey('allows_multiple', $first);
        $this->assertArrayHasKey('requires_versioning', $first);
    }

    public function test_excludes_soft_deleted_type(): void
    {
        $active = $this->makeType(['code' => 'invoice']);
        $retired = $this->makeType(['code' => 'legacy']);
        $retired->delete();

        $response = $this->getJson('/documents/types');

        $response->assertOk();
        $codes = collect($response->json())->pluck('code')->all();
        $this->assertSame(['invoice'], $codes);
    }

    public function test_returns_empty_array_when_no_types_registered(): void
    {
        $response = $this->getJson('/documents/types');

        $response->assertOk();
        $this->assertSame([], $response->json());
    }
}
