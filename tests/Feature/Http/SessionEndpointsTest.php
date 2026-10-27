<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Tests\Fixtures\ConfigurableAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * The HTTP contract of the session flows: a client holds a session id and nothing else, only the session's own
 * user may drive it, and the owner is optional until attach.
 */
class SessionEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        Storage::fake('test_disk');
        FakeMultipartUploadDriver::reset();
    }

    protected function type(string $code = 'DOC', array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => $code,
            'name' => $code,
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'requires_versioning' => true,
        ], $overrides));
    }

    protected function asUser(int $id): static
    {
        return $this->actingAs((new User)->forceFill(['id' => $id]));
    }

    protected function owner(): TestModel
    {
        return TestModel::create(['name' => 'owner']);
    }

    /** @return array<string, mixed> */
    protected function presign(DocumentType $type, int $user = 42): array
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();

        return $this->asUser($user)->postJson('/documents/presigned', ['document_type_id' => $type->id, 'filename' => 'a.txt'])
            ->assertOk()->json();
    }

    protected function putObject(string $sessionId, string $body = 'hello world'): void
    {
        Storage::disk('test_disk')->put(UploadSession::findOrFail($sessionId)->path, $body);
    }

    // Presign and finalize

    public function test_presigning_returns_a_session_id_and_never_the_path(): void
    {
        $response = $this->presign($this->type());

        $this->assertArrayHasKey('session_id', $response);
        $this->assertArrayHasKey('url', $response);
        $this->assertArrayNotHasKey('path', $response);
        $this->assertArrayNotHasKey('upload_id', $response);
        $this->assertSame('42', UploadSession::findOrFail($response['session_id'])->user_id);
    }

    public function test_finalize_without_an_owner_returns_the_uploaded_session(): void
    {
        $type = $this->type();
        $started = $this->presign($type);
        $this->putObject($started['session_id']);

        $this->asUser(42)->postJson('/documents/presigned/finalize', ['session_id' => $started['session_id']])
            ->assertOk()
            ->assertJsonPath('id', $started['session_id'])
            ->assertJsonPath('status', 'uploaded')
            ->assertJsonMissingPath('path');

        $this->assertSame(0, Document::count());
    }

    public function test_finalize_with_an_owner_attaches_in_the_same_call(): void
    {
        $started = $this->presign($this->type());
        $this->putObject($started['session_id']);
        $owner = $this->owner();

        $this->asUser(42)->postJson('/documents/presigned/finalize', [
            'session_id' => $started['session_id'],
            'documentable_type' => 'test_model',
            'documentable_id' => (string) $owner->getKey(),
        ])->assertCreated()->assertJsonPath('client_filename', 'a.txt');

        $this->assertSame(UploadSessionStatus::Attached, UploadSession::findOrFail($started['session_id'])->status);
    }

    public function test_another_users_session_is_forbidden(): void
    {
        $started = $this->presign($this->type(), user: 42);
        $this->putObject($started['session_id']);

        $this->asUser(43)->postJson('/documents/presigned/finalize', ['session_id' => $started['session_id']])
            ->assertForbidden();

        $this->assertSame(UploadSessionStatus::Initiated, UploadSession::findOrFail($started['session_id'])->status);
    }

    public function test_an_unknown_session_is_not_found(): void
    {
        $this->asUser(42)->postJson('/documents/presigned/finalize', ['session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
            ->assertNotFound();
    }

    public function test_finalizing_under_another_document_type_is_unprocessable(): void
    {
        $type = $this->type('DOC');
        $other = $this->type('OTHER');
        $started = $this->presign($type);
        $this->putObject($started['session_id']);

        $this->asUser(42)->postJson('/documents/presigned/finalize', [
            'session_id' => $started['session_id'],
            'document_type_id' => $other->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('document_type_id');
    }

    public function test_a_client_supplied_path_is_ignored_and_cannot_point_finalize_at_another_object(): void
    {
        $started = $this->presign($this->type());
        $this->putObject($started['session_id'], 'mine');
        Storage::disk('test_disk')->put('docs/someone-elses-file', 'theirs');

        $this->asUser(42)->postJson('/documents/presigned/finalize', [
            'session_id' => $started['session_id'],
            'path' => 'docs/someone-elses-file',
        ])->assertOk();

        $this->assertTrue(Storage::disk('test_disk')->exists('docs/someone-elses-file'));
        $this->assertSame(hash('sha256', 'mine'), UploadSession::findOrFail($started['session_id'])->object_hash);
    }

    public function test_the_owner_fields_are_validated_against_the_allowlist_only_when_attaching(): void
    {
        $started = $this->presign($this->type());
        $this->putObject($started['session_id']);

        $this->asUser(42)->postJson('/documents/presigned/finalize', [
            'session_id' => $started['session_id'],
            'documentable_type' => 'App\\Models\\NotAllowed',
            'documentable_id' => '1',
        ])->assertUnprocessable()->assertJsonValidationErrors('documentable_type');
    }

    public function test_an_owner_type_without_an_id_is_unprocessable(): void
    {
        $started = $this->presign($this->type());
        $this->putObject($started['session_id']);

        $this->asUser(42)->postJson('/documents/presigned/finalize', [
            'session_id' => $started['session_id'],
            'documentable_type' => 'test_model',
        ])->assertUnprocessable()->assertJsonValidationErrors('documentable_id');
    }

    public function test_finalize_requires_a_session_id(): void
    {
        $this->asUser(42)->postJson('/documents/presigned/finalize', [])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
    }

    public function test_finalize_rejects_a_malformed_expected_hash(): void
    {
        $started = $this->presign($this->type());

        $this->asUser(42)->postJson('/documents/presigned/finalize', ['session_id' => $started['session_id'], 'expected_hash' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('expected_hash');
    }

    public function test_finalize_reports_an_object_that_was_never_uploaded(): void
    {
        $started = $this->presign($this->type());

        $this->asUser(42)->postJson('/documents/presigned/finalize', ['session_id' => $started['session_id']])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
    }

    public function test_finalize_with_a_wrong_hash_returns_422_and_removes_the_session_object(): void
    {
        $started = $this->presign($this->type());
        $this->putObject($started['session_id']);

        $this->asUser(42)->postJson('/documents/presigned/finalize', [
            'session_id' => $started['session_id'],
            'expected_hash' => hash('sha256', 'different bytes'),
        ])->assertUnprocessable();

        $this->assertFalse(Storage::disk('test_disk')->exists(UploadSession::findOrFail($started['session_id'])->path));
    }

    // Attach

    public function test_attaching_an_uploaded_session_creates_the_document_once(): void
    {
        $started = $this->presign($this->type());
        $this->putObject($started['session_id']);
        $this->asUser(42)->postJson('/documents/presigned/finalize', ['session_id' => $started['session_id']])->assertOk();
        $owner = $this->owner();
        $payload = ['session_id' => $started['session_id'], 'documentable_type' => 'test_model', 'documentable_id' => (string) $owner->getKey()];

        $this->asUser(42)->postJson('/documents/attach', $payload)->assertCreated()->assertJsonPath('version', 1);
        $this->asUser(42)->postJson('/documents/attach', $payload)->assertUnprocessable();

        $this->assertSame(1, Document::count());
    }

    public function test_attach_is_forbidden_for_another_users_session(): void
    {
        $started = $this->presign($this->type(), user: 42);
        $this->putObject($started['session_id']);
        $this->asUser(42)->postJson('/documents/presigned/finalize', ['session_id' => $started['session_id']])->assertOk();
        $owner = $this->owner();

        $this->asUser(43)->postJson('/documents/attach', [
            'session_id' => $started['session_id'],
            'documentable_type' => 'test_model',
            'documentable_id' => (string) $owner->getKey(),
        ])->assertForbidden();
    }

    // Server-proxied upload

    public function test_a_server_upload_with_an_owner_creates_the_document(): void
    {
        $type = $this->type();
        $owner = $this->owner();

        $this->asUser(42)->postJson('/documents', [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'bytes'),
            'document_type_id' => $type->id,
            'documentable_type' => 'test_model',
            'documentable_id' => (string) $owner->getKey(),
        ])->assertCreated()->assertJsonPath('client_filename', 'a.txt');
    }

    public function test_a_server_upload_without_an_owner_returns_a_session_to_attach_later(): void
    {
        $type = $this->type();

        $response = $this->asUser(42)->postJson('/documents', [
            'file' => UploadedFile::fake()->createWithContent('a.txt', 'bytes'),
            'document_type_id' => $type->id,
        ])->assertCreated()->assertJsonPath('status', 'uploaded')->assertJsonMissingPath('path');

        $this->assertSame(0, Document::count());
        $this->assertNotNull(UploadSession::find($response->json('id')));
    }

    public function test_the_detached_endpoint_is_gone(): void
    {
        $status = $this->asUser(42)->postJson('/documents/detached', [])->getStatusCode();

        // /documents/detached now only matches the /{document} pattern, which has no POST route.
        $this->assertContains($status, [404, 405]);
    }

    // Multipart

    protected function initiateMultipart(DocumentType $type, int $user = 42): string
    {
        return $this->asUser($user)->postJson('/documents/multipart/initiate', ['filename' => 'big.bin', 'document_type_id' => $type->id])
            ->assertCreated()
            ->assertJsonMissingPath('path')
            ->assertJsonMissingPath('upload_id')
            ->json('id');
    }

    public function test_the_multipart_lifecycle_runs_on_a_session_id(): void
    {
        $type = $this->type();
        $id = $this->initiateMultipart($type);
        $upload = UploadSession::findOrFail($id)->upload_id;

        $this->asUser(42)->postJson('/documents/multipart/part-url', ['session_id' => $id, 'part_number' => 1])
            ->assertOk()->assertJsonStructure(['url']);

        FakeMultipartUploadDriver::uploadPart($upload, 1, 'hello ');
        FakeMultipartUploadDriver::uploadPart($upload, 2, 'world');

        $this->asUser(42)->getJson('/documents/multipart/parts?session_id='.$id)
            ->assertOk()->assertJsonCount(2, 'parts');

        $this->asUser(42)->getJson('/documents/multipart/status?session_id='.$id)
            ->assertOk()->assertJsonPath('exists', true)->assertJsonPath('status', 'initiated');

        $this->asUser(42)->postJson('/documents/multipart/complete', ['session_id' => $id])
            ->assertOk()->assertJsonPath('status', 'uploaded');
    }

    public function test_completing_a_multipart_session_with_an_owner_attaches_it(): void
    {
        $id = $this->initiateMultipart($this->type());
        FakeMultipartUploadDriver::uploadPart(UploadSession::findOrFail($id)->upload_id, 1, 'hello world');
        $owner = $this->owner();

        $this->asUser(42)->postJson('/documents/multipart/complete', [
            'session_id' => $id,
            'documentable_type' => 'test_model',
            'documentable_id' => (string) $owner->getKey(),
        ])->assertCreated()->assertJsonPath('version', 1);
    }

    public function test_multipart_follow_ups_are_forbidden_for_another_user(): void
    {
        $id = $this->initiateMultipart($this->type(), user: 42);

        $this->asUser(43)->postJson('/documents/multipart/part-url', ['session_id' => $id, 'part_number' => 1])->assertForbidden();
        $this->asUser(43)->getJson('/documents/multipart/parts?session_id='.$id)->assertForbidden();
        $this->asUser(43)->postJson('/documents/multipart/complete', ['session_id' => $id])->assertForbidden();
        $this->asUser(43)->postJson('/documents/multipart/abort', ['session_id' => $id])->assertForbidden();
    }

    public function test_the_status_of_a_session_that_is_not_yours_reports_that_it_does_not_exist(): void
    {
        $id = $this->initiateMultipart($this->type(), user: 42);

        $this->asUser(43)->getJson('/documents/multipart/status?session_id='.$id)
            ->assertOk()->assertJsonPath('exists', false);
    }

    public function test_aborting_a_multipart_session_ends_it(): void
    {
        $id = $this->initiateMultipart($this->type());

        $this->asUser(42)->postJson('/documents/multipart/abort', ['session_id' => $id])->assertNoContent();

        $this->assertSame(UploadSessionStatus::Aborted, UploadSession::findOrFail($id)->status);
    }

    public function test_an_expired_session_refuses_to_complete(): void
    {
        $id = $this->initiateMultipart($this->type());
        FakeMultipartUploadDriver::uploadPart(UploadSession::findOrFail($id)->upload_id, 1, 'x');
        UploadSession::findOrFail($id)->update(['expires_at' => now()->subMinute()]);

        $this->asUser(42)->postJson('/documents/multipart/complete', ['session_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors('session_id');
    }

    // The authorizer decides per owner at attach

    protected function denyOwners(): void
    {
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canAttach'));
    }

    public function test_attaching_to_an_owner_the_authorizer_denies_is_forbidden_and_leaves_the_session_uploaded(): void
    {
        $started = $this->presign($this->type());
        $this->putObject($started['session_id']);
        $this->asUser(42)->postJson('/documents/presigned/finalize', ['session_id' => $started['session_id']])->assertOk();
        $this->denyOwners();

        $this->asUser(42)->postJson('/documents/attach', [
            'session_id' => $started['session_id'],
            'documentable_type' => 'test_model',
            'documentable_id' => (string) $this->owner()->getKey(),
        ])->assertForbidden();

        $this->assertSame(UploadSessionStatus::Uploaded, UploadSession::findOrFail($started['session_id'])->status);
    }

    public function test_completing_a_multipart_session_for_an_owner_the_authorizer_denies_is_forbidden(): void
    {
        $id = $this->initiateMultipart($this->type());
        FakeMultipartUploadDriver::uploadPart(UploadSession::findOrFail($id)->upload_id, 1, 'hello world');
        $this->denyOwners();

        $this->asUser(42)->postJson('/documents/multipart/complete', [
            'session_id' => $id,
            'documentable_type' => 'test_model',
            'documentable_id' => (string) $this->owner()->getKey(),
        ])->assertForbidden();

        $this->assertSame(0, Document::count());
    }
}
