<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `GET /documents/{document}/url` took `disposition` and `expires_in_minutes` straight from the query string:
 * a negative TTL minted an already-expired URL, a huge one a near-permanent link, and an arbitrary
 * disposition string was written into the response header parameter.
 */
class UrlRequestValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
    }

    protected function makeDocument(array $attributes = []): Document
    {
        $type = DocumentType::create([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
        ]);

        $document = $this->app->make(DocumentService::class)->upload(
            UploadedFile::fake()->create('a.txt', 1),
            $type,
            TestModel::create(['name' => 'owner'])
        );

        return $attributes === [] ? $document : tap($document)->update($attributes);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidQueries(): array
    {
        return [
            'unknown disposition' => [['disposition' => 'inline; filename="x"; foo=bar'], 'disposition'],
            'negative ttl' => [['expires_in_minutes' => -5], 'expires_in_minutes'],
            'zero ttl' => [['expires_in_minutes' => 0], 'expires_in_minutes'],
            'ttl above the configured maximum' => [['expires_in_minutes' => 1441], 'expires_in_minutes'],
            'non numeric ttl' => [['expires_in_minutes' => 'soon'], 'expires_in_minutes'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_is_rejected(array $query, string $field): void
    {
        $document = $this->makeDocument();

        $this->getJson("/documents/{$document->id}/url?".http_build_query($query))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    public function test_ttl_ceiling_follows_configuration(): void
    {
        config()->set('documentable.url.max_ttl_minutes', 10);
        $document = $this->makeDocument();

        $this->getJson("/documents/{$document->id}/url?expires_in_minutes=11")->assertStatus(422);
        $this->getJson("/documents/{$document->id}/url?expires_in_minutes=10&disposition=attachment")->assertOk();
    }

    public function test_defaults_still_apply_without_a_query(): void
    {
        $document = $this->makeDocument();

        $this->getJson("/documents/{$document->id}/url")->assertOk()->assertJsonStructure(['url']);
    }

    public function test_expired_pending_document_is_not_found(): void
    {
        $document = $this->makeDocument(['status' => 'pending', 'expires_at' => now()->subMinute()]);

        $this->getJson("/documents/{$document->id}/url")->assertNotFound();
    }

    public function test_unexpired_pending_document_still_resolves(): void
    {
        $document = $this->makeDocument(['status' => 'pending', 'expires_at' => now()->addHour()]);

        $this->getJson("/documents/{$document->id}/url")->assertOk();
    }
}
