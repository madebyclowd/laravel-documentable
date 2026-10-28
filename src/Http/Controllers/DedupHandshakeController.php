<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Dedup\RangeProof;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Http\Requests\HandshakeAnswerRequest;
use MadeByClowd\Documentable\Http\Requests\HandshakeRequest;
use MadeByClowd\Documentable\Models\DedupChallenge;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;
use MadeByClowd\Documentable\Services\ObjectRangeReader;
use MadeByClowd\Documentable\Services\UploadGuard;
use Throwable;

class DedupHandshakeController extends Controller
{
    public function __construct(
        protected AuthorizesDocumentAccess $authorizer,
        protected StorageFileRepository $storageFiles,
        protected UploadSessionRepository $sessions,
        protected ObjectRangeReader $ranges,
    ) {}

    /**
     * Issue a proof-of-possession challenge for deduplication.
     *
     * If the file already exists under the caller's deduplication scope, a challenge is created
     * with 2 to 3 random byte ranges. If the file is not found (a miss), a decoy challenge
     * is returned with the exact same JSON shape to prevent hash-oracle existence attacks.
     */
    public function handshake(HandshakeRequest $request): JsonResponse
    {
        $typeIdentifier = (string) ($request->input('type') ?? $request->input('document_type_id'));
        $type = Str::isUuid($typeIdentifier)
            ? DocumentType::whereNull('deleted_at')->findOrFail($typeIdentifier)
            : DocumentType::whereNull('deleted_at')->where('code', $typeIdentifier)->firstOrFail();

        if (! $this->authorizer->canInitiateUpload($request->user(), $type)) {
            abort(403);
        }

        UploadGuard::assertTypeActive($type);

        $size = $request->integer('size');
        if ($size > $type->max_size_mb * 1024 * 1024) {
            throw ValidationException::withMessages([
                'size' => ["File size exceeds limit of {$type->max_size_mb}MB."],
            ]);
        }

        $owner = $request->filled('documentable_type')
            ? $this->resolveDocumentable($request->string('documentable_type')->toString(), $request->string('documentable_id')->toString())
            : null;

        $hash = strtolower($request->string('hash')->toString());
        $storageFile = $this->storageFiles->findReusable($hash, $type, $request->user(), $owner);

        // A claimed size that is not the stored size is a miss, so the ranges of a hit never depend on a size
        // the caller did not know, and the response stays the same as a miss.
        if ($storageFile !== null && $storageFile->size_bytes !== $size) {
            $storageFile = null;
        }

        $ttl = (int) config('documentable.dedup.challenge_ttl', 300);
        $maxRangeBytes = (int) config('documentable.dedup.max_range_bytes', 64 * 1024);
        $userId = $this->sessionUserKey($request);
        $nonce = bin2hex(random_bytes(32));

        if ($storageFile !== null) {
            $rangeCount = random_int(2, 3);
            $ranges = $this->generateRanges($storageFile->size_bytes, $rangeCount, $maxRangeBytes);

            $challenge = DedupChallenge::create([
                'user_id' => $userId,
                'document_type_id' => $type->id,
                'storage_file_id' => $storageFile->id,
                'scope_key' => $storageFile->file_hash,
                'nonce' => $nonce,
                'ranges' => $ranges,
                'attempts' => 0,
                'expires_at' => now()->addSeconds($ttl),
            ]);

            return response()->json([
                'challenge_id' => $challenge->id,
                'nonce' => $challenge->nonce,
                'ranges' => $challenge->ranges,
            ]);
        }

        // Miss: return a decoy challenge with the exact same JSON shape.
        $rangeCount = random_int(2, 3);
        $ranges = $this->generateRanges($size, $rangeCount, $maxRangeBytes);

        $decoy = DedupChallenge::create([
            'user_id' => $userId,
            'document_type_id' => $type->id,
            'storage_file_id' => null,
            'scope_key' => '',
            'nonce' => $nonce,
            'ranges' => $ranges,
            'attempts' => 0,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        return response()->json([
            'challenge_id' => $decoy->id,
            'nonce' => $decoy->nonce,
            'ranges' => $decoy->ranges,
        ]);
    }

    /**
     * Verify the proof of possession and produce an Uploaded session on success.
     */
    public function answer(HandshakeAnswerRequest $request): JsonResponse
    {
        $challengeId = $request->string('challenge_id')->toString();
        $challenge = DedupChallenge::find($challengeId);

        if ($challenge === null) {
            throw $this->invalidProof();
        }

        // A challenge answered by anyone but the caller it was issued to looks like any other wrong proof and
        // leaves the owner's attempts alone.
        $userId = $this->sessionUserKey($request);
        if ($challenge->user_id !== $userId) {
            throw $this->invalidProof();
        }

        if ($challenge->isExpired()) {
            throw ValidationException::withMessages([
                'proof' => ['The challenge has expired.'],
            ]);
        }

        if ($challenge->isConsumed()) {
            throw ValidationException::withMessages([
                'proof' => ['The challenge has already been consumed.'],
            ]);
        }

        if ($challenge->isDead()) {
            throw ValidationException::withMessages([
                'proof' => ['The challenge has been terminated due to too many failed attempts.'],
            ]);
        }

        $type = DocumentType::withTrashed()->find($challenge->document_type_id);
        if ($type === null) {
            throw $this->invalidProof();
        }

        if (! $this->authorizer->canInitiateUpload($request->user(), $type)) {
            abort(403);
        }

        UploadGuard::assertTypeActive($type);

        // A decoy fails exactly like a wrong proof.
        $storageFile = $challenge->storage_file_id === null ? null : StorageFile::find($challenge->storage_file_id);
        if ($storageFile === null) {
            $challenge->increment('attempts');

            throw $this->invalidProof();
        }

        $expectedProof = $this->expectedProof($challenge, $storageFile);

        $proof = strtolower($request->string('proof')->toString());
        if ($expectedProof === null || ! hash_equals($expectedProof, $proof)) {
            $challenge->increment('attempts');

            throw $this->invalidProof();
        }

        // Atomically consume challenge
        $affected = DedupChallenge::whereKey($challenge->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', 3)
            ->update(['consumed_at' => now()]);

        if ($affected === 0) {
            throw ValidationException::withMessages([
                'proof' => ['The challenge has already been consumed.'],
            ]);
        }

        $sessionTtlHours = (int) config('documentable.multipart.session_ttl_hours', 24);

        $session = $this->sessions->create([
            'user_id' => $userId,
            'document_type_id' => $challenge->document_type_id,
            'disk' => $storageFile->disk,
            'kind' => UploadKind::Single,
            'client_filename' => $request->filled('filename') ? $request->string('filename')->toString() : basename($storageFile->path),
            'path' => $storageFile->path,
            'status' => UploadSessionStatus::Uploaded,
            'storage_file_id' => $storageFile->id,
            'object_size' => $storageFile->size_bytes,
            'object_mime' => $storageFile->mime_type,
            'object_hash' => $storageFile->content_hash,
            'expires_at' => now()->addHours($sessionTtlHours),
        ]);

        return response()->json([
            'session_id' => $session->id,
            'session' => $session,
        ], 201);
    }

    private function invalidProof(): ValidationException
    {
        return ValidationException::withMessages([
            'proof' => ['The provided proof is invalid.'],
        ]);
    }

    /**
     * The proof the caller must send, or null when the stored ranges cannot be read from the object (they are
     * checked against its size first, so a stored row can never make the server read past it).
     */
    private function expectedProof(DedupChallenge $challenge, StorageFile $storageFile): ?string
    {
        $bytes = '';

        // The column is JSON, so what comes back is checked rather than trusted.
        /** @var array<mixed> $stored */
        $stored = $challenge->ranges;

        foreach ($stored as $range) {
            $offset = is_array($range) ? ($range['offset'] ?? null) : null;
            $length = is_array($range) ? ($range['length'] ?? null) : null;

            if (! is_int($offset) || ! is_int($length) || $offset < 0 || $length <= 0 || $offset + $length > $storageFile->size_bytes) {
                return null;
            }

            try {
                $bytes .= $this->ranges->read($storageFile->disk, $storageFile->path, $offset, $length);
            } catch (Throwable) {
                return null;
            }
        }

        return RangeProof::computeFromConcatenated($challenge->nonce, $bytes);
    }

    /**
     * @return list<array{offset: int, length: int}>
     */
    private function generateRanges(int $totalSize, int $count, int $maxRangeBytes): array
    {
        if ($totalSize <= 0) {
            return [['offset' => 0, 'length' => 0]];
        }

        $ranges = [];
        $bound = min($maxRangeBytes, $totalSize);

        for ($i = 0; $i < $count; $i++) {
            $length = max(1, random_int(1, $bound));
            $maxOffset = max(0, $totalSize - $length);
            $offset = random_int(0, $maxOffset);

            $ranges[] = [
                'offset' => $offset,
                'length' => $length,
            ];
        }

        return $ranges;
    }
}
