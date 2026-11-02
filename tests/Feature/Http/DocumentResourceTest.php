<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Http\Resources\DocumentCollection;
use MadeByClowd\Documentable\Http\Resources\DocumentResource;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class DocumentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected DocumentType $type;

    protected TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        $this->service = $this->app->make(DocumentService::class);
        $this->type = DocumentType::create([
            'code' => 'PASSPORT',
            'name' => 'Passport',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'passports',
            'allows_multiple' => true,
            'requires_versioning' => false,
        ]);
        $this->owner = TestModel::create(['name' => 'Owner']);
    }

    public function test_document_resource_contains_expected_fields(): void
    {
        $doc = $this->service->upload(
            UploadedFile::fake()->createWithContent('passport.pdf', 'secret-bytes'),
            $this->type,
            $this->owner
        );

        $resource = new DocumentResource($doc);
        $data = $resource->toArray(Request::create('/'));

        $this->assertSame($doc->id, $data['id']);
        $this->assertSame('passport.pdf', $data['filename']);
        $this->assertSame(strlen('secret-bytes'), $data['size']);
        $this->assertSame('application/pdf', $data['mime']);
        $this->assertSame(1, $data['version']);
        $this->assertSame('PASSPORT', $data['type']);
        $this->assertArrayNotHasKey('url', $data);
    }

    public function test_url_is_absent_by_default_in_serialized_json(): void
    {
        $doc = $this->service->upload(
            UploadedFile::fake()->create('scan.pdf', 1),
            $this->type,
            $this->owner
        );

        $json = (new DocumentResource($doc))->response()->getData(true);

        $this->assertArrayNotHasKey('url', $json['data']);
    }

    public function test_url_is_present_when_resource_built_with_with_url(): void
    {
        $doc = $this->service->upload(
            UploadedFile::fake()->create('scan.pdf', 1),
            $this->type,
            $this->owner
        );

        $resource = (new DocumentResource($doc))->withUrl();
        $data = $resource->toArray(Request::create('/'));

        $this->assertArrayHasKey('url', $data);
        $this->assertNotEmpty($data['url']);
        $this->assertStringStartsWith('http', $data['url']);

        $json = $resource->response()->getData(true);
        $this->assertArrayHasKey('url', $json['data']);
    }

    public function test_document_collection_without_url(): void
    {
        $doc1 = $this->service->upload(UploadedFile::fake()->create('doc1.pdf', 1), $this->type, $this->owner);
        $doc2 = $this->service->upload(UploadedFile::fake()->create('doc2.pdf', 1), $this->type, $this->owner);

        $collection = new DocumentCollection(collect([$doc1, $doc2]));
        $data = $this->decode($collection);

        $this->assertCount(2, $data);
        $this->assertArrayNotHasKey('url', $data[0]);
        $this->assertArrayNotHasKey('url', $data[1]);
    }

    public function test_document_collection_with_url(): void
    {
        $doc1 = $this->service->upload(UploadedFile::fake()->create('doc1.pdf', 1), $this->type, $this->owner);
        $doc2 = $this->service->upload(UploadedFile::fake()->create('doc2.pdf', 1), $this->type, $this->owner);

        $collection = (new DocumentCollection(collect([$doc1, $doc2])))->withUrl();
        $data = $this->decode($collection);

        $this->assertCount(2, $data);
        $this->assertArrayHasKey('url', $data[0]);
        $this->assertArrayHasKey('url', $data[1]);
        $this->assertNotEmpty($data[0]['url']);
        $this->assertNotEmpty($data[1]['url']);
    }

    public function test_document_resource_collection_static_helper_supports_with_url(): void
    {
        $doc = $this->service->upload(UploadedFile::fake()->create('doc.pdf', 1), $this->type, $this->owner);

        $collection = DocumentResource::collection(collect([$doc]))->withUrl();
        $data = $this->decode($collection);

        $this->assertArrayHasKey('url', $data[0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(DocumentCollection $collection): array
    {
        return json_decode($collection->toResponse(Request::create('/'))->getContent(), true)['data'];
    }
}
