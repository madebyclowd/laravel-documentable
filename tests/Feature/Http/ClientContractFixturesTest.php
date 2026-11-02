<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\Support\AssertsClientFixtures;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Validates that server responses match the committed client contract fixtures in resources/client/fixtures/.
 *
 * Walks the client upload lifecycle from config discovery to multipart parts, status and completion,
 * plus deduplication handshake challenge responses.
 */
class ClientContractFixturesTest extends TestCase
{
    use AssertsClientFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        Storage::fake('test_disk');
        RateLimiter::clear('documents-handshake');
        FakeMultipartUploadDriver::reset();
    }

    protected function type(string $code = 'passport', array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => $code,
            'name' => 'Passport',
            'max_size_mb' => 10,
            'allowed_mimes' => ['application/pdf'],
            'allowed_extensions' => ['pdf'],
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'requires_versioning' => true,
        ], $overrides));
    }

    protected function asUser(int $id): static
    {
        return $this->actingAs((new User)->forceFill(['id' => $id]));
    }

    public function test_client_contract_flow_matches_fixtures(): void
    {
        $type = $this->type();

        // 1. GET /documents/config
        $configResponse = $this->asUser(42)->getJson('/documents/config')
            ->assertOk();
        $this->assertMatchesClientFixture('config', $configResponse);

        // 2. POST /documents/multipart/initiate (with a declared size)
        $initiateResponse = $this->asUser(42)->postJson('/documents/multipart/initiate', [
            'document_type_id' => $type->id,
            'filename' => 'large-file.pdf',
            'declared_size' => 5242881, // one byte past the minimum part size: two parts, the smallest upload that has them
        ])->assertCreated();
        $this->assertMatchesClientFixture('multipart-initiate', $initiateResponse);

        $sessionId = (string) $initiateResponse->json('id');
        $session = UploadSession::findOrFail($sessionId);
        $uploadId = (string) $session->upload_id;

        // 3. POST /documents/multipart/part-urls
        $partUrlsResponse = $this->asUser(42)->postJson('/documents/multipart/part-urls', [
            'session_id' => $sessionId,
            'part_numbers' => [1, 2],
        ])->assertOk();
        $this->assertMatchesClientFixture('multipart-part-urls', $partUrlsResponse);

        // Upload parts through FakeMultipartUploadDriver
        FakeMultipartUploadDriver::uploadPart($uploadId, 1, "%PDF-1.4\n".str_repeat('a', 5242880 - 9));
        FakeMultipartUploadDriver::uploadPart($uploadId, 2, 'b');

        // 4. GET /documents/multipart/parts
        $partsResponse = $this->asUser(42)->getJson('/documents/multipart/parts?session_id='.$sessionId)
            ->assertOk();
        $this->assertMatchesClientFixture('multipart-parts', $partsResponse);

        // 5. GET /documents/multipart/status (session exists)
        $statusExistsResponse = $this->asUser(42)->getJson('/documents/multipart/status?session_id='.$sessionId)
            ->assertOk();
        $this->assertMatchesClientFixture('multipart-status-exists', $statusExistsResponse);

        // 6. POST /documents/multipart/complete (uploaded session, no owner)
        $completeResponse = $this->asUser(42)->postJson('/documents/multipart/complete', [
            'session_id' => $sessionId,
        ])->assertOk();
        $this->assertMatchesClientFixture('multipart-complete', $completeResponse);

        // 7. GET /documents/multipart/status (session missing)
        $statusMissingResponse = $this->asUser(42)->getJson('/documents/multipart/status?session_id=01ARZ3NDEKTSV4RRFFQ69G5FAV')
            ->assertOk();
        $this->assertMatchesClientFixture('multipart-status-missing', $statusMissingResponse);

        // 8. POST /documents/handshake (handshake-miss decoy challenge)
        $handshakeMissResponse = $this->asUser(42)->postJson('/documents/handshake', [
            'type' => $type->id,
            'hash' => hash('sha256', 'miss content'),
            'size' => 100,
        ])->assertOk();
        $this->assertMatchesClientFixture('handshake-miss', $handshakeMissResponse);

        // 9. POST /documents/handshake/answer (handshake-answer-invalid 422)
        $handshakeAnswerResponse = $this->asUser(42)->postJson('/documents/handshake/answer', [
            'challenge_id' => (string) $handshakeMissResponse->json('challenge_id'),
            'proof' => hash('sha256', 'invalid proof'),
        ])->assertUnprocessable();
        $this->assertMatchesClientFixture('handshake-answer-invalid', $handshakeAnswerResponse);

        // 10. GET /documents/{id} (document-show committed document)
        $owner = TestModel::create(['name' => 'Owner']);
        $document = $this->app->make(DocumentService::class)->upload(
            UploadedFile::fake()->createWithContent('fixture-doc.pdf', "%PDF-1.4\ncontent"),
            $type,
            $owner,
        );
        $showResponse = $this->asUser(42)->getJson('/documents/'.$document->id)
            ->assertOk();
        // The owner's key is an auto-increment id, and PostgreSQL does not reset a sequence between tests.
        $this->assertMatchesClientFixture('document-show', [...$showResponse->json(), 'documentable_id' => '<owner id>']);
    }
}
