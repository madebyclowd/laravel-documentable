<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Dedup;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Dedup\RangeProof;
use MadeByClowd\Documentable\Defaults\GlobalDedupScope;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DedupChallenge;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentPurger;
use MadeByClowd\Documentable\Services\UploadSessionLifecycle;
use MadeByClowd\Documentable\Tests\Fixtures\AllowAllAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\TenantScopedDedupScope;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class DedupHandshakeHardeningTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $type;

    private const CONTENT = 'Hardening content body, long enough to hold several ranges: 0123456789abcdefghijklmnopqrstuvwxyz';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test_disk');
        RateLimiter::clear('documents-handshake');
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);

        $this->type = DocumentType::create([
            'code' => 'PASSPORT',
            'name' => 'Passport',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'passports',
        ]);
    }

    private function blob(?string $key = null, string $path = 'passports/blob.pdf'): StorageFile
    {
        $hash = hash('sha256', self::CONTENT);
        Storage::disk('test_disk')->put($path, self::CONTENT);

        return StorageFile::create([
            'file_hash' => $key ?? $hash,
            'content_hash' => $hash,
            'disk' => 'test_disk',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen(self::CONTENT),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function handshake(string|int $user, array $extra = [], ?int $size = null): TestResponse
    {
        return $this->signIn($user)->postJson('/documents/handshake', $extra + [
            'type' => $this->type->id,
            'hash' => hash('sha256', self::CONTENT),
            'size' => $size ?? strlen(self::CONTENT),
        ]);
    }

    public function test_handshake_uses_the_owner_to_resolve_the_scope(): void
    {
        $this->app->bind(ResolvesDedupScope::class, TenantScopedDedupScope::class);
        $owner = TestModel::create(['name' => 'tenant-a']);
        $other = TestModel::create(['name' => 'tenant-b']);
        $file = $this->blob($owner->getKey().':'.hash('sha256', self::CONTENT));

        $hit = $this->handshake(1, ['documentable_type' => 'test_model', 'documentable_id' => (string) $owner->getKey()]);
        $hit->assertOk();
        $this->assertSame($file->id, DedupChallenge::findOrFail($hit->json('challenge_id'))->storage_file_id);

        $miss = $this->handshake(1, ['documentable_type' => 'test_model', 'documentable_id' => (string) $other->getKey()]);
        $miss->assertOk();
        $this->assertNull(DedupChallenge::findOrFail($miss->json('challenge_id'))->storage_file_id);
    }

    public function test_handshake_rejects_an_unknown_owner(): void
    {
        $this->handshake(1, ['documentable_type' => 'test_model', 'documentable_id' => '999999'])
            ->assertStatus(422);
    }

    public function test_a_wrong_claimed_size_is_treated_as_a_miss(): void
    {
        $file = $this->blob();

        $response = $this->handshake(1, [], 5_000_000);
        $response->assertOk();

        $challenge = DedupChallenge::findOrFail($response->json('challenge_id'));
        $this->assertNull($challenge->storage_file_id);
        $this->assertNotSame($file->id, $challenge->storage_file_id);
        foreach ($response->json('ranges') as $range) {
            $this->assertLessThanOrEqual(5_000_000, $range['offset'] + $range['length']);
        }
    }

    public function test_answer_requires_the_gate_to_still_allow_the_upload(): void
    {
        $this->blob();
        $handshake = $this->handshake(1)->json();
        $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);

        $this->useAuthorizer(new class extends AllowAllAuthorizer
        {
            public function canInitiateUpload($user, DocumentType $type): bool
            {
                return false;
            }
        });

        $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ])->assertStatus(403);
    }

    public function test_answer_refuses_an_inactive_type(): void
    {
        $this->blob();
        $handshake = $this->handshake(1)->json();
        $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);
        $this->type->delete();

        $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ])->assertStatus(422);
    }

    public function test_a_guest_challenge_cannot_be_answered_by_a_user_and_vice_versa(): void
    {
        $this->blob();

        $guestChallenge = $this->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => hash('sha256', self::CONTENT),
            'size' => strlen(self::CONTENT),
        ])->json();
        $userChallenge = $this->handshake(1)->json();

        $guestProof = RangeProof::computeFromBytes($guestChallenge['nonce'], self::CONTENT, $guestChallenge['ranges']);
        $userProof = RangeProof::computeFromBytes($userChallenge['nonce'], self::CONTENT, $userChallenge['ranges']);

        // A user cannot answer the guest's challenge, and the guest cannot answer the user's.
        $this->signIn(1)->postJson('/documents/handshake/answer', ['challenge_id' => $guestChallenge['challenge_id'], 'proof' => $guestProof])
            ->assertStatus(422);
        $this->app['auth']->forgetGuards();
        $this->postJson('/documents/handshake/answer', ['challenge_id' => $userChallenge['challenge_id'], 'proof' => $userProof])
            ->assertStatus(422);

        // The binding failure neither consumes nor burns the legitimate owner's challenge.
        $this->assertSame(0, DedupChallenge::findOrFail($userChallenge['challenge_id'])->attempts);
        $this->assertNull(DedupChallenge::findOrFail($userChallenge['challenge_id'])->consumed_at);

        $this->signIn(1)->postJson('/documents/handshake/answer', ['challenge_id' => $userChallenge['challenge_id'], 'proof' => $userProof])
            ->assertStatus(201);
    }

    public function test_another_users_answer_is_indistinguishable_from_a_wrong_proof(): void
    {
        $this->blob();
        $handshake = $this->handshake(1)->json();
        $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);

        $this->signIn(2)->postJson('/documents/handshake/answer', ['challenge_id' => $handshake['challenge_id'], 'proof' => $proof])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof');
    }

    public function test_the_session_keeps_the_filename_the_client_sent(): void
    {
        $this->blob();
        $handshake = $this->handshake(1)->json();
        $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);

        $response = $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
            'filename' => 'my passport.pdf',
        ]);

        $response->assertStatus(201);
        $this->assertSame('my passport.pdf', UploadSession::findOrFail($response->json('session_id'))->client_filename);
    }

    public function test_the_session_filename_falls_back_to_the_stored_name(): void
    {
        $this->blob();
        $handshake = $this->handshake(1)->json();
        $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);

        $response = $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ]);

        $this->assertSame('blob.pdf', UploadSession::findOrFail($response->json('session_id'))->client_filename);
    }

    public function test_a_stored_challenge_with_out_of_bounds_ranges_never_reads_past_the_object(): void
    {
        $file = $this->blob();
        $challenge = DedupChallenge::create([
            'user_id' => '1',
            'document_type_id' => $this->type->id,
            'storage_file_id' => $file->id,
            'scope_key' => $file->file_hash,
            'nonce' => str_repeat('a', 64),
            'ranges' => [['offset' => 0, 'length' => strlen(self::CONTENT) + 10]],
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
        ]);

        $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $challenge->id,
            'proof' => hash('sha256', 'x'),
        ])->assertStatus(422);
    }

    public function test_an_open_handshake_session_protects_an_unreferenced_blob_from_the_sweep(): void
    {
        $file = $this->blob();
        $handshake = $this->handshake(1)->json();
        $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);
        $sessionId = $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ])->json('session_id');

        $this->assertFalse(app(DocumentPurger::class)->removeStorageFileIfUnreferenced($file));
        Storage::disk('test_disk')->assertExists($file->path);

        // Once the session ends nothing holds the blob and it can go.
        app(UploadSessionLifecycle::class)->expire(UploadSession::findOrFail($sessionId));
        $this->assertNull(StorageFile::find($file->id));
    }

    public function test_attaching_a_handshake_session_shares_the_blob_and_deleting_one_document_keeps_it(): void
    {
        $file = $this->blob();
        $ids = [];

        foreach ([1, 2] as $user) {
            $owner = TestModel::create(['name' => "o{$user}"]);
            $handshake = $this->handshake($user)->json();
            $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);
            $sessionId = $this->signIn($user)->postJson('/documents/handshake/answer', [
                'challenge_id' => $handshake['challenge_id'],
                'proof' => $proof,
                'filename' => "doc-{$user}.pdf",
            ])->json('session_id');

            $this->signIn($user)->postJson('/documents/attach', [
                'session_id' => $sessionId,
                'documentable_type' => 'test_model',
                'documentable_id' => (string) $owner->getKey(),
                'document_type_id' => $this->type->id,
            ])->assertStatus(201);
            $ids[] = $sessionId;
        }

        $this->assertSame(1, StorageFile::count());
        $this->assertSame(2, Document::where('storage_file_id', $file->id)->count());
        foreach ($ids as $id) {
            $this->assertSame(UploadSessionStatus::Attached, UploadSession::findOrFail($id)->status);
        }

        Document::query()->first()->delete();
        app(DocumentPurger::class)->removeStorageFileIfUnreferenced($file);

        Storage::disk('test_disk')->assertExists($file->path);
        $this->assertNotNull(StorageFile::find($file->id));
    }

    public function test_the_type_can_be_named_by_code(): void
    {
        $this->blob();

        $this->handshake(1, ['type' => 'PASSPORT'])->assertOk();
    }

    public function test_a_size_over_the_type_limit_is_refused(): void
    {
        $this->handshake(1, [], 11 * 1024 * 1024)->assertStatus(422)->assertJsonValidationErrors('size');
    }

    public function test_an_unknown_challenge_looks_like_a_wrong_proof(): void
    {
        $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => (string) Str::uuid(),
            'proof' => hash('sha256', 'x'),
        ])->assertStatus(422)->assertJsonValidationErrors('proof');
    }

    public function test_a_stored_file_that_is_gone_fails_like_a_wrong_proof(): void
    {
        $file = $this->blob();
        $handshake = $this->handshake(1)->json();
        $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);
        $file->delete();

        $this->signIn(1)->postJson('/documents/handshake/answer', ['challenge_id' => $handshake['challenge_id'], 'proof' => $proof])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof');
    }

    public function test_an_object_missing_from_the_disk_fails_like_a_wrong_proof(): void
    {
        $file = $this->blob();
        $handshake = $this->handshake(1)->json();
        $proof = RangeProof::computeFromBytes($handshake['nonce'], self::CONTENT, $handshake['ranges']);
        Storage::disk('test_disk')->delete($file->path);

        $this->signIn(1)->postJson('/documents/handshake/answer', ['challenge_id' => $handshake['challenge_id'], 'proof' => $proof])
            ->assertStatus(422)
            ->assertJsonValidationErrors('proof');
        $this->assertSame(1, DedupChallenge::findOrFail($handshake['challenge_id'])->attempts);
    }

    public function test_an_empty_file_has_nothing_to_prove_and_gets_a_zero_length_range(): void
    {
        $empty = hash('sha256', '');
        Storage::disk('test_disk')->put('passports/empty.pdf', '');
        StorageFile::create([
            'file_hash' => $empty,
            'content_hash' => $empty,
            'disk' => 'test_disk',
            'path' => 'passports/empty.pdf',
            'mime_type' => 'text/plain',
            'size_bytes' => 0,
        ]);

        $response = $this->handshake(1, ['hash' => $empty], 0);

        $response->assertOk();
        $this->assertSame([['offset' => 0, 'length' => 0]], $response->json('ranges'));
    }
}
