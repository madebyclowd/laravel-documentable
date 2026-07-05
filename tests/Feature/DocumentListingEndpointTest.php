<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/feedback.md #6 — no endpoint to list
 * "all of this owner's documents, grouped by slot". See
 * docs/implementations/v2.0.0/phase-12-document-listing-endpoint.md.
 */
class DocumentListingEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
    }

    /**
     * data is {document_type_id: {document_group_id: <document array>}} —
     * flatten a level per type/group without descending into each document's
     * own attribute array (Collection::flatten($depth) can't tell a "leaf
     * document" from "one more nesting level" and would shred it).
     */
    protected function flattenDocuments(array $data): Collection
    {
        return collect($data)->flatMap(fn ($groups) => collect($groups)->values());
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'DOC-'.uniqid(),
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
        ], $overrides));
    }

    public function test_returns_only_documents_for_given_owner(): void
    {
        $type = $this->makeType();
        $ownerA = TestModel::create(['name' => 'A']);
        $ownerB = TestModel::create(['name' => 'B']);

        $service = app(DocumentService::class);
        $docA = $service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $ownerA);
        $service->upload(UploadedFile::fake()->create('b.txt', 1), $type, $ownerB);

        $response = $this->getJson('/documents?documentable_type='.$ownerA->getMorphClass().'&documentable_id='.$ownerA->getKey());

        $response->assertOk();
        $flat = $this->flattenDocuments($response->json('data'));
        $this->assertCount(1, $flat);
        $this->assertSame($docA->id, $flat->first()['id']);
    }

    public function test_groups_by_type_and_returns_only_latest_version(): void
    {
        $type = $this->makeType(['requires_versioning' => true, 'allows_multiple' => false]);
        $owner = TestModel::create(['name' => 'owner']);

        $service = app(DocumentService::class);
        $service->upload(UploadedFile::fake()->create('v1.txt', 1), $type, $owner);
        $latest = $service->upload(UploadedFile::fake()->create('v2.txt', 1), $type, $owner);

        $response = $this->getJson('/documents?documentable_type='.$owner->getMorphClass().'&documentable_id='.$owner->getKey());

        $response->assertOk();
        $flat = $this->flattenDocuments($response->json('data'));
        $this->assertCount(1, $flat);
        $this->assertSame($latest->id, $flat->first()['id']);
        $this->assertSame(2, $flat->first()['version']);
    }

    public function test_document_type_id_filter_narrows_to_one_type(): void
    {
        $typeA = $this->makeType(['code' => 'A']);
        $typeB = $this->makeType(['code' => 'B']);
        $owner = TestModel::create(['name' => 'owner']);

        $service = app(DocumentService::class);
        $service->upload(UploadedFile::fake()->create('a.txt', 1), $typeA, $owner);
        $service->upload(UploadedFile::fake()->create('b.txt', 1), $typeB, $owner);

        $response = $this->getJson('/documents?documentable_type='.$owner->getMorphClass().
            '&documentable_id='.$owner->getKey().'&document_type_id='.$typeA->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey($typeA->id, $data);
        $this->assertArrayNotHasKey($typeB->id, $data);
    }

    public function test_storage_file_is_eager_loaded_without_n_plus_one(): void
    {
        $type = $this->makeType(['allows_multiple' => true]);
        $owner = TestModel::create(['name' => 'owner']);

        $service = app(DocumentService::class);
        $service->upload(UploadedFile::fake()->create('one.txt', 1), $type, $owner);
        $service->upload(UploadedFile::fake()->create('two.txt', 1), $type, $owner);
        $service->upload(UploadedFile::fake()->create('three.txt', 1), $type, $owner);

        $query = '/documents?documentable_type='.$owner->getMorphClass().'&documentable_id='.$owner->getKey();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson($query);
        $countForThree = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $flat = $this->flattenDocuments($response->json('data'));
        $this->assertCount(3, $flat);
        foreach ($flat as $document) {
            $this->assertNotNull($document['storage_file']['mime_type'] ?? null);
        }

        // Query count must not scale with document count — proves storageFile is
        // eager-loaded (with()), not lazy-loaded per row.
        $owner2 = TestModel::create(['name' => 'owner2']);
        $service->upload(UploadedFile::fake()->create('solo.txt', 1), $type, $owner2);
        $query2 = '/documents?documentable_type='.$owner2->getMorphClass().'&documentable_id='.$owner2->getKey();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($query2)->assertOk();
        $countForOne = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($countForOne, $countForThree);
    }

    public function test_custom_authorizer_excludes_only_denied_documents(): void
    {
        $this->app->bind(AuthorizesDocumentAccess::class, fn () => new class implements AuthorizesDocumentAccess
        {
            public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $documentable): bool
            {
                return true;
            }

            public function canView(?Authenticatable $user, Document $document): bool
            {
                return $document->client_filename !== 'secret.txt';
            }

            public function canDelete(?Authenticatable $user, Document $document): bool
            {
                return true;
            }
        });

        $type = $this->makeType(['allows_multiple' => true]);
        $owner = TestModel::create(['name' => 'owner']);

        $service = app(DocumentService::class);
        $service->upload(UploadedFile::fake()->create('secret.txt', 1), $type, $owner);
        $visible = $service->upload(UploadedFile::fake()->create('visible.txt', 1), $type, $owner);

        $response = $this->getJson('/documents?documentable_type='.$owner->getMorphClass().'&documentable_id='.$owner->getKey());

        $response->assertOk();
        $flat = $this->flattenDocuments($response->json('data'));
        $this->assertCount(1, $flat);
        $this->assertSame($visible->id, $flat->first()['id']);
    }

    public function test_unmapped_documentable_type_rejected(): void
    {
        $owner = TestModel::create(['name' => 'owner']);

        config()->set('documentable.security.allowed_documentable_types', null);

        $response = $this->getJson('/documents?documentable_type='.urlencode(TestModel::class).'&documentable_id='.$owner->getKey());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['documentable_type']);
    }

    public function test_pagination_respects_configured_per_page(): void
    {
        config()->set('documentable.listing.per_page', 1);

        $type = $this->makeType(['allows_multiple' => true]);
        $owner = TestModel::create(['name' => 'owner']);

        $service = app(DocumentService::class);
        $service->upload(UploadedFile::fake()->create('one.txt', 1), $type, $owner);
        $service->upload(UploadedFile::fake()->create('two.txt', 1), $type, $owner);

        $query = '/documents?documentable_type='.$owner->getMorphClass().'&documentable_id='.$owner->getKey();

        $page1 = $this->getJson($query.'&page=1');
        $page1->assertOk();
        $this->assertSame(1, $page1->json('meta.per_page'));
        $this->assertSame(2, $page1->json('meta.total'));
        $this->assertSame(2, $page1->json('meta.last_page'));
        $this->assertCount(1, $this->flattenDocuments($page1->json('data')));

        $page2 = $this->getJson($query.'&page=2');
        $page2->assertOk();
        $this->assertCount(1, $this->flattenDocuments($page2->json('data')));

        $page1Id = $this->flattenDocuments($page1->json('data'))->first()['id'];
        $page2Id = $this->flattenDocuments($page2->json('data'))->first()['id'];
        $this->assertNotSame($page1Id, $page2Id);
    }
}
