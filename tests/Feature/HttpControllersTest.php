<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
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

    public function test_store_denied_when_authorizer_rejects_upload(): void
    {
        $this->app->bind(AuthorizesDocumentAccess::class, fn () => new class implements AuthorizesDocumentAccess
        {
            public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $documentable): bool
            {
                return false;
            }

            public function canView(?Authenticatable $user, Document $document): bool
            {
                return true;
            }

            public function canDelete(?Authenticatable $user, Document $document): bool
            {
                return true;
            }
        });

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $response = $this->postJson('/documents', [
            'file' => UploadedFile::fake()->create('a.txt', 1),
            'document_type_id' => $type->id,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ]);

        $response->assertStatus(403);
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

        $presign = $this->postJson('/documents/presigned', [
            'document_type_id' => $type->id,
            'filename' => 'small.txt',
        ])->assertOk()->json();

        Storage::disk('test_disk')->put($presign['path'], 'small content');

        $finalize = $this->postJson('/documents/presigned/finalize', [
            'path' => $presign['path'],
            'document_type_id' => $type->id,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
            'filename' => 'small.txt',
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

        $initiate = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
            'user_id' => 'user-1',
        ])->assertCreated()->json();

        $etag = FakeMultipartUploadDriver::uploadPart($initiate['upload_id'], 1, 'hello world');

        $complete = $this->postJson('/documents/multipart/complete', [
            'path' => $initiate['path'],
            'upload_id' => $initiate['upload_id'],
            'document_type_id' => $type->id,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
            'filename' => 'f.bin',
            'user_id' => 'user-1',
            'expected_hash' => hash('sha256', 'hello world'),
        ]);

        $complete->assertCreated();
        $this->assertNotNull($complete->json('storage_file.mime_type'));
        $this->assertNotNull($complete->json('storage_file.size_bytes'));

        $initiate2 = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'g.bin',
            'document_type_id' => $type->id,
            'user_id' => 'user-2',
        ])->assertCreated()->json();

        $abort = $this->postJson('/documents/multipart/abort', [
            'path' => $initiate2['path'],
            'upload_id' => $initiate2['upload_id'],
            'document_type_id' => $type->id,
            'user_id' => 'user-2',
        ]);

        $abort->assertNoContent();
    }

    public function test_multipart_list_parts_and_status_via_http(): void
    {
        $type = $this->makeType(['code' => 'BIG', 'name' => 'Big']);

        $initiate = $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
            'user_id' => 'user-1',
        ])->assertCreated()->json();

        FakeMultipartUploadDriver::uploadPart($initiate['upload_id'], 1, 'hello world');

        $parts = $this->getJson('/documents/multipart/parts?'.http_build_query([
            'path' => $initiate['path'],
            'upload_id' => $initiate['upload_id'],
            'document_type_id' => $type->id,
            'user_id' => 'user-1',
        ]));

        $parts->assertOk();
        $this->assertSame([1], collect($parts->json('parts'))->pluck('PartNumber')->all());

        $status = $this->getJson('/documents/multipart/status?'.http_build_query([
            'path' => $initiate['path'],
            'upload_id' => $initiate['upload_id'],
            'user_id' => 'user-1',
        ]));

        $status->assertOk();
        $this->assertTrue($status->json('exists'));

        $goneStatus = $this->getJson('/documents/multipart/status?'.http_build_query([
            'path' => 'nowhere/gone.bin',
            'upload_id' => 'not-a-real-upload-id',
            'user_id' => 'user-1',
        ]));

        $goneStatus->assertOk();
        $this->assertFalse($goneStatus->json('exists'));
    }
}
