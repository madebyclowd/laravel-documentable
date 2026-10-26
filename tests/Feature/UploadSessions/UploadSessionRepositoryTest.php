<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\UploadSessions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;

class UploadSessionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private UploadSessionRepository $repository;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(UploadSessionRepository::class);
        $this->type = DocumentType::create(['code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 's3', 'path_prefix' => 'docs']);
    }

    private function open(?string $userId = '42', array $overrides = []): UploadSession
    {
        return $this->repository->create(array_merge([
            'user_id' => $userId,
            'document_type_id' => $this->type->id,
            'disk' => 's3',
            'kind' => UploadKind::Single,
            'path' => 'docs/'.uniqid(),
            'expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function user(int|string $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    public function test_a_new_session_starts_initiated(): void
    {
        $this->assertSame(UploadSessionStatus::Initiated, $this->open()->status);
    }

    public function test_declared_metadata_is_stored_from_the_value_object(): void
    {
        $session = $this->repository->create([
            'document_type_id' => $this->type->id,
            'disk' => 's3',
            'kind' => UploadKind::Single,
            'path' => 'docs/x',
            'expires_at' => now()->addHour(),
        ] + (new UploadSessionData(sizeBytes: 10, mimeType: 'text/plain', sha256: str_repeat('b', 64)))->toColumns());

        $this->assertSame(10, $session->fresh()->declared_size);
        $this->assertSame('text/plain', $session->fresh()->declared_mime);
    }

    public function test_the_owner_finds_the_session_and_a_stranger_does_not(): void
    {
        $session = $this->open('42');

        $this->assertTrue($this->repository->findOwnedOrFail($session->id, $this->user(42))->is($session));

        $this->expectException(ModelNotFoundException::class);
        $this->repository->findOwnedOrFail($session->id, $this->user(43));
    }

    public function test_an_unauthenticated_caller_only_reaches_sessions_without_a_user(): void
    {
        $guestSession = $this->open(null);
        $userSession = $this->open('42');

        $this->assertTrue($this->repository->findOwnedOrFail($guestSession->id, null)->is($guestSession));

        $this->expectException(ModelNotFoundException::class);
        $this->repository->findOwnedOrFail($userSession->id, null);
    }

    public function test_an_authenticated_user_cannot_reach_a_guest_session(): void
    {
        $guestSession = $this->open(null);

        $this->expectException(ModelNotFoundException::class);
        $this->repository->findOwnedOrFail($guestSession->id, $this->user(42));
    }

    public function test_an_unknown_id_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);
        $this->repository->findOwnedOrFail('01ARZ3NDEKTSV4RRFFQ69G5FAV', $this->user(42));
    }

    public function test_expired_before_returns_only_open_sessions_past_their_expiry(): void
    {
        $stale = $this->open('1', ['expires_at' => now()->subMinute()]);
        $staleUploaded = $this->open('1', ['expires_at' => now()->subMinute()]);
        $this->repository->markUploaded($staleUploaded);
        $fresh = $this->open('1', ['expires_at' => now()->addHour()]);
        $attached = $this->open('1', ['expires_at' => now()->subMinute()]);
        $this->repository->markUploaded($attached);
        $this->repository->markAttached($attached, TestModel::create(['name' => 'o']));
        $aborted = $this->open('1', ['expires_at' => now()->subMinute()]);
        $this->repository->markAborted($aborted);

        $ids = $this->repository->expiredBefore(now())->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$stale->id, $staleUploaded->id], $ids);
        $this->assertNotContains($fresh->id, $ids);
    }

    public function test_mark_uploaded_records_the_storage_file(): void
    {
        $file = StorageFile::create(['file_hash' => 'h', 'disk' => 's3', 'path' => 'docs/a', 'mime_type' => 'text/plain', 'size_bytes' => 1]);
        $session = $this->open();

        $updated = $this->repository->markUploaded($session, $file);

        $this->assertSame(UploadSessionStatus::Uploaded, $updated->status);
        $this->assertSame($file->id, $updated->fresh()->storage_file_id);
    }

    public function test_a_stale_copy_cannot_mark_a_session_uploaded_twice(): void
    {
        $first = $this->open();
        $second = UploadSession::find($first->id);

        $this->repository->markUploaded($first);

        $this->expectException(IllegalUploadSessionTransition::class);
        $this->repository->markUploaded($second);
    }

    public function test_mark_attached_stores_the_owner_by_morph_class_and_key(): void
    {
        $owner = TestModel::create(['name' => 'o']);
        $session = $this->repository->markUploaded($this->open());

        $attached = $this->repository->markAttached($session, $owner);

        $this->assertSame(UploadSessionStatus::Attached, $attached->status);
        $this->assertSame($owner->getMorphClass(), $attached->fresh()->owner_type);
        $this->assertSame((string) $owner->getKey(), $attached->fresh()->owner_id);
    }

    public function test_a_session_cannot_be_attached_before_it_is_uploaded(): void
    {
        $this->expectException(IllegalUploadSessionTransition::class);

        $this->repository->markAttached($this->open(), TestModel::create(['name' => 'o']));
    }

    public function test_an_attached_session_cannot_be_attached_again(): void
    {
        $owner = TestModel::create(['name' => 'o']);
        $session = $this->repository->markUploaded($this->open());
        $this->repository->markAttached($session, $owner);

        $this->expectException(IllegalUploadSessionTransition::class);
        $this->repository->markAttached(UploadSession::find($session->id), $owner);
    }

    public function test_expiring_and_aborting_move_an_open_session_to_a_terminal_state(): void
    {
        $this->assertSame(UploadSessionStatus::Expired, $this->repository->markExpired($this->open())->status);
        $this->assertSame(UploadSessionStatus::Aborted, $this->repository->markAborted($this->open())->status);
    }
}
