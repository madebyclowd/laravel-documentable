<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Tests\Fixtures\ConfigurableAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every follow-up on an upload session reaches the authorizer with the session itself, so an app can refuse
 * `finalize` for a session it should not complete. A `user_id` in the request is never honoured as an owner.
 */
class AuthorizationGapsTest extends TestCase
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

    protected function makeType(string $code = 'BIG'): DocumentType
    {
        return DocumentType::create([
            'code' => $code,
            'name' => $code,
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
        ]);
    }

    /**
     * @return array{id: string}
     */
    protected function initiateSession(DocumentType $type, int $userId = 1): array
    {
        return $this->signIn($userId)->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
        ])->assertCreated()->json();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function followUpEndpoints(): array
    {
        return [
            'part-url' => ['POST', '/documents/multipart/part-url'],
            'parts' => ['GET', '/documents/multipart/parts'],
            'status' => ['GET', '/documents/multipart/status'],
            'abort' => ['POST', '/documents/multipart/abort'],
        ];
    }

    #[DataProvider('followUpEndpoints')]
    public function test_follow_up_endpoints_are_forbidden_when_the_authorizer_denies_the_session(string $method, string $uri): void
    {
        $session = $this->initiateSession($this->makeType());
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canFinalize'));

        $payload = ['session_id' => $session['id'], 'part_number' => 1];

        $response = $method === 'GET'
            ? $this->getJson($uri.'?'.http_build_query($payload))
            : $this->postJson($uri, $payload);

        $response->assertForbidden();
    }

    public function test_the_authorizer_is_asked_about_the_session_being_driven(): void
    {
        $session = $this->initiateSession($this->makeType());
        $asked = [];
        $this->useAuthorizer(ConfigurableAuthorizer::with([
            'canFinalize' => function ($user, UploadSession $driven) use (&$asked) {
                $asked[] = [$user?->getAuthIdentifier(), $driven->id, $driven->user_id];

                return true;
            },
        ]));

        $this->getJson('/documents/multipart/parts?'.http_build_query(['session_id' => $session['id']]))->assertOk();

        $this->assertSame([[1, $session['id'], '1']], $asked);
    }

    public function test_status_cannot_be_used_to_probe_a_session_of_a_type_the_caller_may_not_drive(): void
    {
        $allowed = $this->makeType('ALLOWED');
        $denied = $this->makeType('DENIED');
        $session = $this->initiateSession($denied);
        $this->useAuthorizer(ConfigurableAuthorizer::with([
            'canFinalize' => fn ($user, UploadSession $driven) => $driven->document_type_id === $allowed->id,
        ]));

        $this->getJson('/documents/multipart/status?'.http_build_query(['session_id' => $session['id']]))->assertForbidden();
    }

    public function test_status_of_an_unknown_session_still_reports_it_does_not_exist(): void
    {
        $this->makeType();

        $this->signIn(1)->getJson('/documents/multipart/status?'.http_build_query([
            'session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ]))->assertOk()->assertJson(['exists' => false]);
    }

    public function test_complete_is_refused_when_the_authorizer_rejects_finalizing_the_session(): void
    {
        $session = $this->initiateSession($this->makeType());
        FakeMultipartUploadDriver::uploadPart(UploadSession::findOrFail($session['id'])->upload_id, 1, 'hello world');
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canFinalize'));

        $this->postJson('/documents/multipart/complete', ['session_id' => $session['id']])->assertForbidden();

        $this->assertSame(UploadSessionStatus::Initiated, UploadSession::findOrFail($session['id'])->status);
    }

    public function test_direct_finalize_is_refused_when_the_authorizer_rejects_finalizing_the_session(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();

        $type = $this->makeType('SMALL');
        $started = $this->signIn(1)->postJson('/documents/presigned', [
            'document_type_id' => $type->id,
            'filename' => 'small.txt',
        ])->assertOk()->json();
        Storage::disk('test_disk')->put(UploadSession::findOrFail($started['session_id'])->path, 'small content');
        $this->useAuthorizer(ConfigurableAuthorizer::denying('canFinalize'));

        $this->postJson('/documents/presigned/finalize', ['session_id' => $started['session_id']])->assertForbidden();
        $this->postJson('/documents/attach', [
            'session_id' => $started['session_id'],
            'documentable_type' => 'test_model',
            'documentable_id' => (string) TestModel::create(['name' => 'owner'])->getKey(),
        ])->assertForbidden();

        $this->assertSame(UploadSessionStatus::Initiated, UploadSession::findOrFail($started['session_id'])->status);
    }

    public function test_initiate_ignores_a_client_supplied_user_id_for_an_anonymous_caller(): void
    {
        $type = $this->makeType();

        $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
            'user_id' => 'victim',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $this->assertSame(0, UploadSession::count());
    }

    public function test_the_authenticated_user_wins_over_a_client_supplied_user_id(): void
    {
        $type = $this->makeType();

        $this->signIn(7)->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
            'user_id' => 'victim',
        ])->assertCreated();

        $this->assertSame(1, UploadSession::where('user_id', '7')->count());
        $this->assertSame(0, UploadSession::where('user_id', 'victim')->count());
    }

    public function test_a_client_supplied_user_id_cannot_reach_someone_elses_session(): void
    {
        $session = $this->initiateSession($this->makeType(), 10);

        $this->signIn(11)->postJson('/documents/multipart/part-url', [
            'session_id' => $session['id'],
            'part_number' => 1,
            'user_id' => '10',
        ])->assertForbidden();
    }

    public function test_an_anonymous_presign_stores_an_anonymous_session_whatever_user_id_says(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();

        $type = $this->makeType('SMALL');

        $started = $this->postJson('/documents/presigned', [
            'document_type_id' => $type->id,
            'filename' => 'small.txt',
            'user_id' => 'victim',
        ])->assertOk()->json();

        $this->assertNull(UploadSession::findOrFail($started['session_id'])->user_id);
    }

    public function test_type_listing_only_returns_types_the_authorizer_allows(): void
    {
        $allowed = $this->makeType('ALLOWED');
        $this->makeType('HIDDEN');
        $this->useAuthorizer(ConfigurableAuthorizer::with([
            'canInitiateUpload' => fn ($user, DocumentType $type) => $type->id === $allowed->id,
        ]));

        $response = $this->getJson('/documents/types')->assertOk();

        $this->assertSame(['ALLOWED'], collect($response->json())->pluck('code')->all());
    }
}
