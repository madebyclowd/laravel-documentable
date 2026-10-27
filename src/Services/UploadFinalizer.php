<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;

/**
 * Turns an `initiated` session whose bytes have landed into an `uploaded` one, after checking the object.
 *
 * Up to `verification.sync_hash_max_bytes` the whole object is verified here. A larger one gets only the checks
 * that cost a request or two (size, the first bytes) and is marked `uploaded` unverified; a job finishes the
 * work, so the request never waits on reading gigabytes.
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
        private readonly DocumentableConfig $config,
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
            if ($facts === null && $this->verifier->size($session->disk, $session->path) > $this->config->syncHashMaxBytes) {
                return $this->deferVerification($session, $expectedHash);
            }

            $facts ??= $this->verifier->hashAndDetectMime($session->disk, $session->path);

            $this->assertAcceptable($session, $facts, $expectedHash);
        } catch (ValidationException $e) {
            $this->discard($session);

            throw $e;
        }

        return $this->markUploaded(fn () => $this->sessions->markVerified($session, $facts), $session);
    }

    /**
     * Check what is cheap on a large object (its size and the content type read from its first bytes) and leave
     * the hash, the client's claim and the scanner to the verification job.
     *
     * @throws ValidationException
     */
    private function deferVerification(UploadSession $session, ?string $expectedHash): UploadSession
    {
        $size = $this->verifier->size($session->disk, $session->path);
        $head = $this->verifier->head($session->disk, $session->path);

        $this->assertDeclaredSize($session, $size);
        $this->assertTypeAllows($session, $size, $head);

        return $this->markUploaded(fn () => $this->sessions->markUploadedUnverified($session, $size, $this->verifier->mimeOf($head), $expectedHash), $session);
    }

    /**
     * @param  callable(): UploadSession  $mark
     */
    private function markUploaded(callable $mark, UploadSession $session): UploadSession
    {
        try {
            return $mark();
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
     * Everything a finished object must satisfy: the declared and permitted size, a permitted content type, the
     * client's hash, and a clean scanner verdict. Used in the request for a small object and by the job for a
     * large one, so both hold an upload to the same rules.
     *
     * @param  array{hash: string, mime: string, size: int}  $facts
     *
     * @throws ValidationException
     */
    public function assertAcceptable(UploadSession $session, array $facts, ?string $expectedHash = null): void
    {
        $this->assertDeclaredSize($session, $facts['size']);
        $this->assertTypeAllows($session, $facts['size'], $this->verifier->head($session->disk, $session->path));
        $this->verifier->assertIntegrity($facts['hash'], $expectedHash ?? $session->declared_hash);
        $this->verifier->assertScanClean($session->disk, $session->path);
    }

    /**
     * @throws ValidationException
     */
    private function assertDeclaredSize(UploadSession $session, int $size): void
    {
        if ($session->declared_size !== null && $size !== $session->declared_size) {
            throw ValidationException::withMessages([
                'file' => "Uploaded file size ({$size}) does not match the declared size ({$session->declared_size}).",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertTypeAllows(UploadSession $session, int $size, string $head): void
    {
        $type = $session->documentTypeOrFail();

        UploadGuard::assertTypeActive($type);

        $this->verifier->assertUploadRules($type, $session->client_filename, $size, $head);
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
