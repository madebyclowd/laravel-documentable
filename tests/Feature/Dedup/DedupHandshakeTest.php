<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Dedup;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Dedup\RangeProof;
use MadeByClowd\Documentable\Defaults\GlobalDedupScope;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DedupChallenge;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Tests\Fixtures\AllowAllAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class DedupHandshakeTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test_disk');
        RateLimiter::clear('documents-handshake');

        $this->type = DocumentType::create([
            'code' => 'PASSPORT',
            'name' => 'Passport',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'passports',
        ]);
    }

    public function test_handshake_unauthorized_user_is_forbidden(): void
    {
        $denyingAuthorizer = new class extends AllowAllAuthorizer
        {
            public function canInitiateUpload($user, DocumentType $type): bool
            {
                return false;
            }
        };
        $this->useAuthorizer($denyingAuthorizer);

        $response = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => hash('sha256', 'content'),
            'size' => 7,
        ]);

        $response->assertStatus(403);
    }

    public function test_hit_and_miss_return_identical_json_shape(): void
    {
        $content = 'Sample content for hit testing';
        $hash = hash('sha256', $content);
        $path = 'passports/hit.pdf';
        Storage::disk('test_disk')->put($path, $content);

        // Under GlobalDedupScope, bare hash matches
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);

        $storageFile = StorageFile::create([
            'file_hash' => $hash,
            'content_hash' => $hash,
            'disk' => 'test_disk',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
        ]);

        // HIT
        $hitResponse = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => $hash,
            'size' => strlen($content),
        ]);
        $hitResponse->assertStatus(200);
        $hitData = $hitResponse->json();

        // MISS
        $missHash = hash('sha256', 'non existent content');
        $missResponse = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => $missHash,
            'size' => 100,
        ]);
        $missResponse->assertStatus(200);
        $missData = $missResponse->json();

        // Assert equal key sets
        $this->assertEqualsCanonicalizing(['challenge_id', 'nonce', 'ranges'], array_keys($hitData));
        $this->assertEqualsCanonicalizing(['challenge_id', 'nonce', 'ranges'], array_keys($missData));

        $this->assertIsArray($hitData['ranges']);
        $this->assertIsArray($missData['ranges']);
        $this->assertGreaterThanOrEqual(2, count($hitData['ranges']));
        $this->assertLessThanOrEqual(3, count($hitData['ranges']));

        foreach ($hitData['ranges'] as $range) {
            $this->assertArrayHasKey('offset', $range);
            $this->assertArrayHasKey('length', $range);
            $this->assertGreaterThanOrEqual(0, $range['offset']);
            $this->assertGreaterThan(0, $range['length']);
            $this->assertLessThanOrEqual(strlen($content), $range['offset'] + $range['length']);
        }
    }

    public function test_answer_with_valid_proof_creates_uploaded_session_and_attaches(): void
    {
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);

        $content = 'Large content body for proof verification '.str_repeat('x', 500);
        $hash = hash('sha256', $content);
        $path = 'passports/verified.pdf';
        Storage::disk('test_disk')->put($path, $content);

        $storageFile = StorageFile::create([
            'file_hash' => $hash,
            'content_hash' => $hash,
            'disk' => 'test_disk',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
        ]);

        $handshake = $this->signIn(1)->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => $hash,
            'size' => strlen($content),
        ])->json();

        $proof = RangeProof::computeFromBytes($handshake['nonce'], $content, $handshake['ranges']);

        $answerResponse = $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ]);

        $answerResponse->assertStatus(201);
        $sessionId = $answerResponse->json('session_id') ?? $answerResponse->json('id');
        $this->assertNotNull($sessionId);

        $session = UploadSession::find($sessionId);
        $this->assertNotNull($session);
        $this->assertSame(UploadSessionStatus::Uploaded, $session->status);
        $this->assertSame($storageFile->id, $session->storage_file_id);
        $this->assertSame($hash, $session->object_hash);
        $this->assertSame('1', $session->user_id);

        // Attach via standard attach endpoint
        $owner = TestModel::create(['name' => 'Owner']);
        $attachResponse = $this->signIn(1)->postJson('/documents/attach', [
            'session_id' => $sessionId,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ]);

        $attachResponse->assertStatus(201);
        $this->assertSame($storageFile->id, $attachResponse->json('storage_file.id'));
    }

    public function test_wrong_proof_is_rejected_and_increments_attempts(): void
    {
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);

        $content = 'Secret content';
        $hash = hash('sha256', $content);
        $path = 'passports/secret.pdf';
        Storage::disk('test_disk')->put($path, $content);

        StorageFile::create([
            'file_hash' => $hash,
            'content_hash' => $hash,
            'disk' => 'test_disk',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
        ]);

        $handshake = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => $hash,
            'size' => strlen($content),
        ])->json();

        $wrongProof = hash('sha256', 'incorrect-proof');

        $response = $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $wrongProof,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('proof');

        $challenge = DedupChallenge::find($handshake['challenge_id']);
        $this->assertSame(1, $challenge->attempts);
    }

    public function test_decoy_challenge_fails_with_same_error_as_wrong_proof(): void
    {
        $handshake = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => hash('sha256', 'miss content'),
            'size' => 100,
        ])->json();

        $response = $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => hash('sha256', 'any proof'),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('proof');
    }

    public function test_three_wrong_answers_kill_the_challenge(): void
    {
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);

        $content = 'Kill challenge test content';
        $hash = hash('sha256', $content);
        $path = 'passports/kill.pdf';
        Storage::disk('test_disk')->put($path, $content);

        StorageFile::create([
            'file_hash' => $hash,
            'content_hash' => $hash,
            'disk' => 'test_disk',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
        ]);

        $handshake = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => $hash,
            'size' => strlen($content),
        ])->json();

        $wrongProof = hash('sha256', 'wrong');

        // Attempt 1
        $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $wrongProof,
        ])->assertStatus(422);

        // Attempt 2
        $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $wrongProof,
        ])->assertStatus(422);

        // Attempt 3
        $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $wrongProof,
        ])->assertStatus(422);

        $challenge = DedupChallenge::find($handshake['challenge_id']);
        $this->assertSame(3, $challenge->attempts);
        $this->assertTrue($challenge->isDead());

        // Now attempt with valid proof must still be rejected
        $validProof = RangeProof::computeFromBytes($handshake['nonce'], $content, $handshake['ranges']);
        $response = $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $validProof,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('proof');
    }

    public function test_challenge_cannot_be_reused(): void
    {
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);

        $content = 'Single use test content';
        $hash = hash('sha256', $content);
        $path = 'passports/reuse.pdf';
        Storage::disk('test_disk')->put($path, $content);

        StorageFile::create([
            'file_hash' => $hash,
            'content_hash' => $hash,
            'disk' => 'test_disk',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
        ]);

        $handshake = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => $hash,
            'size' => strlen($content),
        ])->json();

        $proof = RangeProof::computeFromBytes($handshake['nonce'], $content, $handshake['ranges']);

        // 1st answer succeeds
        $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ])->assertStatus(201);

        // 2nd answer fails
        $response = $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('proof');
    }

    public function test_expired_challenge_fails(): void
    {
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);

        $content = 'Expired test content';
        $hash = hash('sha256', $content);
        $path = 'passports/expired.pdf';
        Storage::disk('test_disk')->put($path, $content);

        StorageFile::create([
            'file_hash' => $hash,
            'content_hash' => $hash,
            'disk' => 'test_disk',
            'path' => $path,
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
        ]);

        $handshake = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => $hash,
            'size' => strlen($content),
        ])->json();

        // Expire the challenge
        DedupChallenge::whereKey($handshake['challenge_id'])->update([
            'expires_at' => now()->subMinutes(10),
        ]);

        $proof = RangeProof::computeFromBytes($handshake['nonce'], $content, $handshake['ranges']);

        $response = $this->signIn('user-1')->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('proof');
    }

    public function test_processing_unverified_files_are_never_handshake_targets(): void
    {
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);

        $hash = hash('sha256', 'processing content');

        // File is pending / unverified
        $pendingFile = StorageFile::create([
            'file_hash' => 'pending:'.(string) Str::uuid(),
            'content_hash' => null,
            'disk' => 'test_disk',
            'path' => 'passports/pending.pdf',
            'mime_type' => 'text/plain',
            'size_bytes' => 100,
        ]);

        $handshake = $this->signIn('user-1')->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => $hash,
            'size' => 100,
        ])->json();

        // Must be a decoy challenge (storage_file_id is null)
        $challenge = DedupChallenge::find($handshake['challenge_id']);
        $this->assertNull($challenge->storage_file_id);
    }

    public function test_repeated_handshake_attempts_hit_rate_limit(): void
    {
        // 60 requests allowed per minute by default
        for ($i = 0; $i < 60; $i++) {
            $response = $this->signIn(1)->postJson('/documents/handshake', [
                'type' => $this->type->id,
                'hash' => hash('sha256', 'rl-content'),
                'size' => 10,
            ]);
            $response->assertStatus(200);
        }

        // 61st request should hit rate limit (429)
        $rateLimited = $this->signIn(1)->postJson('/documents/handshake', [
            'type' => $this->type->id,
            'hash' => hash('sha256', 'rl-content'),
            'size' => 10,
        ]);

        $rateLimited->assertStatus(429);
    }
}
