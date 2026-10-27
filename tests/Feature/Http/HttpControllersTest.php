<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\ConfigurableAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class HttpControllersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        FakeMultipartUploadDriver::reset();
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
        ], $overrides));
    }

    public function test_store_uploads_a_document_via_http(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $response = $this->postJson('/documents', [
            'file' => UploadedFile::fake()->create('a.txt', 1),
            'document_type_id' => $type->id,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ]);

        $response->assertCreated();
        $this->assertSame(1, Document::count());
        $this->assertNotNull($response->json('storage_file.mime_type'));
        $this->assertNotNull($response->json('storage_file.size_bytes'));
    }

    public function test_store_rejects_soft_deleted_document_type(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $type->delete();

        $response = $this->postJson('/documents', [
            'file' => UploadedFile::fake()->create('a.txt', 1),
            'document_type_id' => $type->id,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['document_type_id']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function storePayload(DocumentType $type, TestModel $owner): array
    {
        return [
            'file' => UploadedFile::fake()->create('a.txt', 1),
            'document_type_id' => $type->id,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ];
    }

    public function test_store_with_an_owner_stops_at_initiate_when_it_is_denied(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $authorizer = $this->useAuthorizer(ConfigurableAuthorizer::denying('canInitiateUpload'));

        $this->postJson('/documents', $this->storePayload($type, $owner))->assertForbidden();

        $this->assertSame(['canInitiateUpload'], $authorizer->asked);
    }

    public function test_store_with_an_owner_asks_attach_about_an_unsaved_document_of_the_type(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $authorizer = $this->useAuthorizer(ConfigurableAuthorizer::with([
            'canAttach' => function ($user, $source, $attachTo) use ($type, $owner) {
                $this->assertInstanceOf(Document::class, $source);
                $this->assertFalse($source->exists);
                $this->assertSame($type->id, $source->document_type_id);
                $this->assertTrue($attachTo->is($owner));

                return false;
            },
        ]));

        $this->postJson('/documents', $this->storePayload($type, $owner))->assertForbidden();

        $this->assertSame(['canInitiateUpload', 'canAttach'], $authorizer->asked);
        $this->assertSame(0, Document::count());
        $this->assertSame(0, UploadSession::count());
    }

    public function test_url_endpoint_returns_signed_url(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $document = app(DocumentService::class)->upload(
            UploadedFile::fake()->create('a.txt', 1),
            $type,
            $owner
        );

        $response = $this->getJson("/documents/{$document->id}/url");

        $response->assertOk();
        $this->assertNotEmpty($response->json('url'));
    }

    public function test_destroy_soft_deletes_document(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $document = app(DocumentService::class)->upload(
            UploadedFile::fake()->create('a.txt', 1),
            $type,
            $owner
        );

        $response = $this->deleteJson("/documents/{$document->id}");

        $response->assertNoContent();
        $this->assertNotNull($document->fresh()->deleted_at);
    }

    public function test_presign_and_finalize_direct_upload_via_http(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $this->signIn(1);

        $presign = $this->postJson('/documents/presigned', [
            'document_type_id' => $type->id,
            'filename' => 'small.txt',
        ])->assertOk()->json();

        Storage::disk('test_disk')->put(UploadSession::findOrFail($presign['session_id'])->path, 'small content');

        $finalize = $this->postJson('/documents/presigned/finalize', [
            'session_id' => $presign['session_id'],
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
            'expected_hash' => hash('sha256', 'small content'),
        ]);

        $finalize->assertCreated();
        $this->assertNotNull($finalize->json('storage_file.mime_type'));
        $this->assertNotNull($finalize->json('storage_file.size_bytes'));
    }

    public function test_multipart_initiate_complete_and_abort_via_http(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);
        $owner = TestModel::create(['name' => 'owner']);
        $this->signIn(1);

        $initiate = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
        ])->assertCreated()->json();

        FakeMultipartUploadDriver::uploadPart(UploadSession::findOrFail($initiate['id'])->upload_id, 1, 'hello world');

        $complete = $this->postJson('/documents/multipart/complete', [
            'session_id' => $initiate['id'],
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
            'expected_hash' => hash('sha256', 'hello world'),
        ]);

        $complete->assertCreated();
        $this->assertNotNull($complete->json('storage_file.mime_type'));
        $this->assertNotNull($complete->json('storage_file.size_bytes'));

        $this->signIn(2);

        $initiate2 = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'g.bin',
            'document_type_id' => $type->id,
        ])->assertCreated()->json();

        $this->postJson('/documents/multipart/abort', [
            'session_id' => $initiate2['id'],
        ])->assertNoContent();
    }

    public function test_multipart_list_parts_and_status_via_http(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);
        $this->signIn(1);

        $initiate = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
        ])->assertCreated()->json();

        FakeMultipartUploadDriver::uploadPart(UploadSession::findOrFail($initiate['id'])->upload_id, 1, 'hello world');

        $parts = $this->getJson('/documents/multipart/parts?'.http_build_query([
            'session_id' => $initiate['id'],
        ]));

        $parts->assertOk();
        $this->assertSame([1], collect($parts->json('parts'))->pluck('PartNumber')->all());

        $status = $this->getJson('/documents/multipart/status?'.http_build_query([
            'session_id' => $initiate['id'],
        ]));

        $status->assertOk();
        $this->assertTrue($status->json('exists'));

        $goneStatus = $this->getJson('/documents/multipart/status?'.http_build_query([
            'session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ]));

        $goneStatus->assertOk();
        $this->assertFalse($goneStatus->json('exists'));
    }

    public function test_store_without_an_owner_stages_the_upload_as_a_session_via_http(): void
    {
        $type = $this->makeType();

        $response = $this->postJson('/documents', [
            'file' => UploadedFile::fake()->create('a.txt', 1),
            'document_type_id' => $type->id,
        ]);

        $response->assertCreated()->assertJsonPath('status', 'uploaded');
        $this->assertSame(0, Document::count());
        $this->assertSame(1, UploadSession::count());
    }

    public function test_store_without_an_owner_is_denied_when_the_authorizer_rejects_initiating_the_upload(): void
    {
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canInitiateUpload'));

        $type = $this->makeType();

        $this->postJson('/documents', [
            'file' => UploadedFile::fake()->create('a.txt', 1),
            'document_type_id' => $type->id,
        ])->assertStatus(403);

        $this->assertSame(0, UploadSession::count());
    }

    public function test_url_denied_when_authorizer_rejects_view(): void
    {
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canView'));

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = app(DocumentService::class)->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $response = $this->getJson("/documents/{$document->id}/url");

        $response->assertStatus(403);
    }

    public function test_destroy_denied_when_authorizer_rejects_delete(): void
    {
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canDelete'));

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = app(DocumentService::class)->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $response = $this->deleteJson("/documents/{$document->id}");

        $response->assertStatus(403);
        $this->assertNull($document->fresh()->deleted_at);
    }

    public function test_multipart_part_url_via_http(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);
        $this->signIn(1);

        $initiate = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
        ])->assertCreated()->json();

        $response = $this->postJson('/documents/multipart/part-url', [
            'session_id' => $initiate['id'],
            'part_number' => 1,
        ]);

        $response->assertOk();
        $this->assertStringContainsString(UploadSession::findOrFail($initiate['id'])->upload_id, $response->json('url'));
    }

    public function test_multipart_initiate_denied_when_authorizer_rejects_initiating_the_upload(): void
    {
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canInitiateUpload'));

        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);
        $this->signIn(1);

        $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
        ])->assertStatus(403);
    }

    public function test_multipart_initiate_needs_an_authenticated_user(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);

        $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);
    }

    public function test_multipart_complete_denied_when_authorizer_rejects_attaching_to_the_owner(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);
        $owner = TestModel::create(['name' => 'owner']);
        $this->signIn(1);

        $initiate = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
        ])->assertCreated()->json();

        FakeMultipartUploadDriver::uploadPart(UploadSession::findOrFail($initiate['id'])->upload_id, 1, 'hello world');

        $this->useAuthorizer(ConfigurableAuthorizer::denying('canAttach'));

        $this->postJson('/documents/multipart/complete', [
            'session_id' => $initiate['id'],
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ])->assertStatus(403);
    }

    public function test_part_url_is_forbidden_without_the_sessions_user_when_unauthenticated(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);
        $this->signIn(1);

        $initiate = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
        ])->assertCreated()->json();

        $this->app['auth']->forgetGuards();

        $this->postJson('/documents/multipart/part-url', [
            'session_id' => $initiate['id'],
            'part_number' => 1,
        ])->assertForbidden();
    }

    public function test_presign_denied_when_authorizer_rejects_initiating_the_upload(): void
    {
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canInitiateUpload'));

        $type = $this->makeType();

        $response = $this->postJson('/documents/presigned', [
            'document_type_id' => $type->id,
            'filename' => 'small.txt',
        ]);

        $response->assertStatus(403);
    }

    public function test_finalize_denied_when_authorizer_rejects_the_session(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $this->signIn(1);

        $presign = $this->postJson('/documents/presigned', [
            'document_type_id' => $type->id,
            'filename' => 'small.txt',
        ])->assertOk()->json();

        Storage::disk('test_disk')->put(UploadSession::findOrFail($presign['session_id'])->path, 'small content');

        $this->useAuthorizer(ConfigurableAuthorizer::denying('canFinalize'));

        $this->postJson('/documents/presigned/finalize', [
            'session_id' => $presign['session_id'],
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ])->assertStatus(403);

        $this->assertSame('initiated', UploadSession::findOrFail($presign['session_id'])->status->value);
    }

    public function test_index_rejects_documentable_that_does_not_exist(): void
    {
        $owner = TestModel::create(['name' => 'owner']);

        $response = $this->getJson('/documents?documentable_type='.$owner->getMorphClass().'&documentable_id=999999');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['documentable_id']);
    }
}
