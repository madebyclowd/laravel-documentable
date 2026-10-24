<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The multipart follow-up endpoints (part-url, parts, status, abort) used to skip the authorizer that
 * `initiate` and `complete` already call, `user_id` was trusted from the request body, and `/documents/types`
 * listed every type to every caller.
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
        config()->set('documentable.security.allow_guest_user_id', true);
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
     * @return array{path: string, upload_id: string}
     */
    protected function initiateSession(DocumentType $type, string $userId = 'user-1'): array
    {
        return $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
            'user_id' => $userId,
        ])->assertCreated()->json();
    }

    protected function bindAuthorizer(bool $canUpload, ?callable $canTypeBeUploaded = null): void
    {
        $this->app->bind(AuthorizesDocumentAccess::class, fn () => new class($canUpload, $canTypeBeUploaded) implements AuthorizesDocumentAccess
        {
            public function __construct(private bool $canUpload, private $perType) {}

            public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $documentable): bool
            {
                return $this->perType !== null ? (bool) ($this->perType)($type) : $this->canUpload;
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
    public function test_follow_up_endpoints_are_forbidden_when_the_authorizer_denies_upload(string $method, string $uri): void
    {
        $type = $this->makeType();
        $session = $this->initiateSession($type);
        $this->bindAuthorizer(canUpload: false);

        $payload = [
            'path' => $session['path'],
            'upload_id' => $session['upload_id'],
            'document_type_id' => $type->id,
            'part_number' => 1,
            'user_id' => 'user-1',
        ];

        $response = $method === 'GET'
            ? $this->getJson($uri.'?'.http_build_query($payload))
            : $this->postJson($uri, $payload);

        $response->assertForbidden();
    }

    public function test_status_cannot_be_used_to_probe_a_session_of_a_type_the_caller_may_not_upload(): void
    {
        $allowed = $this->makeType('ALLOWED');
        $denied = $this->makeType('DENIED');
        $session = $this->initiateSession($denied);
        $this->bindAuthorizer(canUpload: true, canTypeBeUploaded: fn (DocumentType $type) => $type->id === $allowed->id);

        $this->getJson('/documents/multipart/status?'.http_build_query([
            'path' => $session['path'],
            'upload_id' => $session['upload_id'],
            'user_id' => 'user-1',
        ]))->assertForbidden();
    }

    public function test_status_of_an_unknown_session_still_reports_it_does_not_exist(): void
    {
        $this->makeType();

        $this->getJson('/documents/multipart/status?'.http_build_query([
            'path' => 'nowhere/gone.bin',
            'upload_id' => 'not-a-real-upload-id',
            'user_id' => 'user-1',
        ]))->assertOk()->assertJson(['exists' => false]);
    }

    public function test_initiate_ignores_a_client_supplied_user_id_by_default(): void
    {
        config()->set('documentable.security.allow_guest_user_id', false);
        $type = $this->makeType();

        $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
            'user_id' => 'victim',
        ])->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $this->assertSame(0, MultipartUpload::where('user_id', 'victim')->count());
    }

    public function test_initiate_accepts_a_guest_user_id_when_explicitly_allowed(): void
    {
        config()->set('documentable.security.allow_guest_user_id', true);
        $type = $this->makeType();

        $this->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
            'user_id' => 'device-9',
        ])->assertCreated();

        $this->assertSame(1, MultipartUpload::where('user_id', 'device-9')->count());
    }

    public function test_authenticated_user_wins_over_a_client_supplied_user_id(): void
    {
        config()->set('documentable.security.allow_guest_user_id', true);
        $type = $this->makeType();

        $this->actingAs(new class extends User
        {
            public function getAuthIdentifier(): string
            {
                return 'real-user';
            }
        })->postJson('/documents/multipart/initiate', [
            'filename' => 'f.bin',
            'document_type_id' => $type->id,
            'user_id' => 'victim',
        ])->assertCreated();

        $this->assertSame(1, MultipartUpload::where('user_id', 'real-user')->count());
        $this->assertSame(0, MultipartUpload::where('user_id', 'victim')->count());
    }

    public function test_type_listing_only_returns_types_the_authorizer_allows(): void
    {
        $allowed = $this->makeType('ALLOWED');
        $this->makeType('HIDDEN');
        $this->bindAuthorizer(canUpload: true, canTypeBeUploaded: fn (DocumentType $type) => $type->id === $allowed->id);

        $response = $this->getJson('/documents/types')->assertOk();

        $this->assertSame(['ALLOWED'], collect($response->json())->pluck('code')->all());
    }
}
