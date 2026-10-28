<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Dedup;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\HasDedupTenant;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Dedup\RangeProof;
use MadeByClowd\Documentable\Defaults\GlobalDedupScope;
use MadeByClowd\Documentable\Defaults\TypeAndTenantScope;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class DedupExposureRegressionTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $typeA;

    private DocumentType $typeB;

    private DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test_disk');
        $this->service = $this->app->make(DocumentService::class);

        $this->typeA = DocumentType::create([
            'code' => 'PASSPORT',
            'name' => 'Passport',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'passports',
        ]);

        $this->typeB = DocumentType::create([
            'code' => 'FINANCIAL',
            'name' => 'Financial Report',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'financials',
        ]);
    }

    /**
     * Sitarung & uatrak exposure scenario:
     * Tenant A uploads a secret blob. Tenant B knows only Tenant A's content hash.
     * Under default TypeAndTenantScope, Tenant B cannot obtain a session, attach Tenant A's blob,
     * or get a presigned URL to it.
     */
    public function test_tenant_b_knowing_tenant_a_hash_cannot_obtain_session_or_access(): void
    {
        // Tenant A model
        $tenantA = new class extends TestModel implements HasDedupTenant
        {
            public function dedupTenantId(): ?string
            {
                return 'tenant-A';
            }
        };
        $tenantA->save();

        // Tenant B model
        $tenantB = new class extends TestModel implements HasDedupTenant
        {
            public function dedupTenantId(): ?string
            {
                return 'tenant-B';
            }
        };
        $tenantB->save();

        // Tenant A uploads file
        $secretContent = 'Top secret document of Tenant A '.str_repeat('A', 200);
        $fileHash = hash('sha256', $secretContent);

        $file = UploadedFile::fake()->createWithContent('secret.pdf', $secretContent);
        $docA = $this->signIn('user-A')->service->upload($file, $this->typeA, $tenantA);

        $this->assertSame(1, StorageFile::count());
        $storageFileA = $docA->storageFile;
        $this->assertSame($fileHash, $storageFileA->content_hash);

        // Tenant B (User B) calls handshake for the same hash under Tenant B's context
        TypeAndTenantScope::resolveTenantUsing(fn ($owner, $user, $type) => 'tenant-B');

        try {
            $handshake = $this->signIn('user-B')->postJson('/documents/handshake', [
                'type' => $this->typeA->id,
                'hash' => $fileHash,
                'size' => strlen($secretContent),
            ])->json();

            // Under TypeAndTenantScope, Tenant B experiences a miss (decoy challenge)
            // Even if Tenant B manages to calculate the proof for secretContent:
            $proof = RangeProof::computeFromBytes($handshake['nonce'], $secretContent, $handshake['ranges']);

            $answerResponse = $this->signIn('user-B')->postJson('/documents/handshake/answer', [
                'challenge_id' => $handshake['challenge_id'],
                'proof' => $proof,
            ]);

            // Answer must fail — Tenant B cannot obtain a session
            $answerResponse->assertStatus(422);

            // Tenant B cannot attach or get a URL
            $this->assertNull(StorageFile::where('file_hash', hash('sha256', "{$this->typeA->id}|tenant-B|{$fileHash}"))->first());
        } finally {
            TypeAndTenantScope::resolveTenantUsing(null);
        }
    }

    public function test_same_tenant_duplicate_dedups_after_correct_proof(): void
    {
        TypeAndTenantScope::resolveTenantUsing(fn ($owner, $user, $type) => 'tenant-A');

        try {
            $content = 'Invoice #100 bytes '.str_repeat('I', 300);
            $hash = hash('sha256', $content);

            $tenantA = TestModel::create(['name' => 'Tenant A Model']);
            $file = UploadedFile::fake()->createWithContent('invoice1.pdf', $content);
            $doc1 = $this->signIn('user-A')->service->upload($file, $this->typeA, $tenantA);

            $this->assertSame(1, StorageFile::count());

            // Tenant A uploads same file via handshake
            $handshake = $this->signIn('user-A')->postJson('/documents/handshake', [
                'type' => $this->typeA->id,
                'hash' => $hash,
                'size' => strlen($content),
            ])->json();

            $proof = RangeProof::computeFromBytes($handshake['nonce'], $content, $handshake['ranges']);

            $answerResponse = $this->signIn('user-A')->postJson('/documents/handshake/answer', [
                'challenge_id' => $handshake['challenge_id'],
                'proof' => $proof,
            ]);

            $answerResponse->assertStatus(201);
            $sessionId = $answerResponse->json('session_id') ?? $answerResponse->json('id');

            // Attach
            $doc2 = $this->signIn('user-A')->postJson('/documents/attach', [
                'session_id' => $sessionId,
                'documentable_type' => $tenantA->getMorphClass(),
                'documentable_id' => (string) $tenantA->getKey(),
            ])->json();

            // Still only 1 StorageFile row exists (deduplicated)
            $this->assertSame(1, StorageFile::count());
            $this->assertSame($doc1->storage_file_id, $doc2['storage_file_id']);
        } finally {
            TypeAndTenantScope::resolveTenantUsing(null);
        }
    }

    public function test_cross_type_does_not_dedup_under_default_scope(): void
    {
        $content = 'Shared template bytes '.str_repeat('T', 200);
        $hash = hash('sha256', $content);
        $owner = TestModel::create(['name' => 'Owner']);

        // Default scope: Type A upload
        $file1 = UploadedFile::fake()->createWithContent('file1.pdf', $content);
        $this->service->upload($file1, $this->typeA, $owner);

        // Default scope: Type B handshake with same hash -> miss (decoy challenge)
        $handshake = $this->signIn(1)->postJson('/documents/handshake', [
            'type' => $this->typeB->id,
            'hash' => $hash,
            'size' => strlen($content),
        ])->json();

        $proof = RangeProof::computeFromBytes($handshake['nonce'], $content, $handshake['ranges']);

        // Cannot answer decoy challenge
        $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $handshake['challenge_id'],
            'proof' => $proof,
        ])->assertStatus(422);
    }

    public function test_global_scope_dedups_across_types(): void
    {
        config()->set('documentable.dedup.scope_resolver', GlobalDedupScope::class);
        $this->app->bind(ResolvesDedupScope::class, GlobalDedupScope::class);
        $service = $this->app->make(DocumentService::class);

        $content = 'Global cross-type bytes '.str_repeat('G', 200);
        $hash = hash('sha256', $content);
        $owner = TestModel::create(['name' => 'Owner']);

        // Upload under Type A under GlobalDedupScope
        $file = UploadedFile::fake()->createWithContent('file.pdf', $content);
        $docGlobalA = $service->upload($file, $this->typeA, $owner);

        // Under GlobalDedupScope, Type B handshake hits Type A's stored file!
        $globalHandshake = $this->signIn(1)->postJson('/documents/handshake', [
            'type' => $this->typeB->id,
            'hash' => $hash,
            'size' => strlen($content),
        ])->json();

        $globalProof = RangeProof::computeFromBytes($globalHandshake['nonce'], $content, $globalHandshake['ranges']);

        $globalAnswer = $this->signIn(1)->postJson('/documents/handshake/answer', [
            'challenge_id' => $globalHandshake['challenge_id'],
            'proof' => $globalProof,
        ]);

        $globalAnswer->assertStatus(201);
        $sessionId = $globalAnswer->json('session_id') ?? $globalAnswer->json('id');

        $docGlobalB = $this->signIn(1)->postJson('/documents/attach', [
            'session_id' => $sessionId,
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => (string) $owner->getKey(),
        ])->json();

        $this->assertSame($docGlobalA->storage_file_id, $docGlobalB['storage_file_id']);
    }
}
