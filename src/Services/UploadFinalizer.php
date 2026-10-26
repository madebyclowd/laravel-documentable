<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;

/**
 * Turns an `initiated` session whose bytes have landed into an `uploaded` one, after checking the object.
 *
 * The path belongs to the session and the server generated it, so a failed check may delete the object: nobody
 * else can be pointing at it. The session is aborted along with it, because its object is gone.
 *
 * @internal
 */
final class UploadFinalizer
{
    public function __construct(
        private readonly ObjectVerifier $verifier,
        private readonly UploadSessionRepository $sessions,
    ) {}

    /**
     * Idempotent: a session that is already uploaded (or attached) is returned as it is, without reading the
     * object again, so a client retrying a finalize call after a timeout does no harm.
     *
     * @param  array{hash: string, mime: string, size: int}|null  $facts  already measured by the caller (multipart assembly); null measures the object here
     * @param  string|null  $expectedHash  overrides the hash declared at initiate
     *
     * @throws ValidationException
     */
    public function finalize(UploadSession $session, ?array $facts = null, ?string $expectedHash = null): UploadSession
    {
        if ($session->status === UploadSessionStatus::Uploaded || $session->status === UploadSessionStatus::Attached) {
            return $session;
        }

        $this->assertUsable($session);

        if (! Storage::disk($session->disk)->exists($session->path)) {
            throw ValidationException::withMessages(['session_id' => 'No file has been uploaded for this session yet.']);
        }

        try {
            $facts ??= $this->verifier->hashAndDetectMime($session->disk, $session->path);

            $this->check($session, $facts, $expectedHash);
        } catch (ValidationException $e) {
            $this->discard($session);

            throw $e;
        }

        try {
            return $this->sessions->markVerified($session, $facts);
        } catch (IllegalUploadSessionTransition) {
            // A concurrent finalize won the race; its result stands.
            return $session->fresh() ?? $session;
        }
    }

    /**
     * @throws ValidationException
     */
    public function assertUsable(UploadSession $session): void
    {
        if ($session->status !== UploadSessionStatus::Initiated && $session->status !== UploadSessionStatus::Uploaded) {
            throw ValidationException::withMessages(['session_id' => 'This upload session can no longer be used.']);
        }

        if ($session->expires_at->isPast()) {
            throw ValidationException::withMessages(['session_id' => 'This upload session has expired. Start a new upload.']);
        }
    }

    /**
     * @param  array{hash: string, mime: string, size: int}  $facts
     *
     * @throws ValidationException
     */
    private function check(UploadSession $session, array $facts, ?string $expectedHash): void
    {
        $type = $session->documentTypeOrFail();

        UploadGuard::assertTypeActive($type);

        if ($session->declared_size !== null && $facts['size'] !== $session->declared_size) {
            throw ValidationException::withMessages([
                'file' => "Uploaded file size ({$facts['size']}) does not match the declared size ({$session->declared_size}).",
            ]);
        }

        $this->verifier->assertSizeAndMime($facts['size'], $facts['mime'], $type);
        $this->verifier->assertIntegrity($facts['hash'], $expectedHash ?? $session->declared_hash);
        $this->verifier->assertScanClean($session->disk, $session->path);
    }

    /**
     * Abort first, delete second. The abort re-reads the row under a lock and refuses if the session has moved
     * on (attached, say, by a request that raced this one), in which case the object now belongs to a document
     * and must stay.
     */
    private function discard(UploadSession $session): void
    {
        try {
            $this->sessions->markAborted($session);
        } catch (IllegalUploadSessionTransition) {
            return;
        }

        Storage::disk($session->disk)->delete($session->path);
    }
}
