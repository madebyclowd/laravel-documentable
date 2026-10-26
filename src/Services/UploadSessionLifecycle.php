<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Events\MultipartUploadAborted;
use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;

/**
 * Ends an open session, by the owner's request (abort) or because it ran out of time (expire): releases the
 * provider upload or the stored object it holds, and records the final state. The reaper and the abort
 * endpoint both go through here.
 *
 * @internal
 */
final class UploadSessionLifecycle
{
    public function __construct(
        private readonly UploadSessionRepository $sessions,
        private readonly MultipartDriverResolver $drivers,
        private readonly DocumentPurger $purger,
    ) {}

    public function abort(UploadSession $session): UploadSession
    {
        return $this->end($session, UploadSessionStatus::Aborted);
    }

    public function expire(UploadSession $session): UploadSession
    {
        return $this->end($session, UploadSessionStatus::Expired);
    }

    private function end(UploadSession $session, UploadSessionStatus $to): UploadSession
    {
        // A provider upload still open is aborted before the state changes, so a provider that cannot be reached
        // leaves the session open for the next sweep to retry.
        if ($this->holdsOpenProviderUpload($session)) {
            $this->abortProviderUpload($session);
        }

        // The transition re-reads the row under a lock. If the session moved on in the meantime (attached by a
        // request that raced this one), nothing it holds may be deleted.
        try {
            $ended = $to === UploadSessionStatus::Expired
                ? $this->sessions->markExpired($session)
                : $this->sessions->markAborted($session);
        } catch (IllegalUploadSessionTransition) {
            return $session->fresh() ?? $session;
        }

        $this->discardStoredObject($ended);

        if ($ended->kind === UploadKind::Multipart) {
            Event::dispatch(new MultipartUploadAborted($ended));
        }

        return $ended;
    }

    /**
     * A multipart session whose parts were never assembled: verification has not measured an object yet.
     */
    private function holdsOpenProviderUpload(UploadSession $session): bool
    {
        return $session->kind === UploadKind::Multipart && $session->upload_id !== null && $session->object_hash === null;
    }

    private function abortProviderUpload(UploadSession $session): void
    {
        try {
            $this->drivers->forDisk($session->disk)->abort($session->disk, $session->path, (string) $session->upload_id);
        } catch (MultipartUploadNotFoundException) {
            // Already gone on the provider: the abort has reached its goal.
        }
    }

    /**
     * Deletes what an ended session still holds: the stored file if one owns the object (and nothing else
     * references it), otherwise the object itself.
     */
    private function discardStoredObject(UploadSession $session): void
    {
        if ($session->storage_file_id !== null) {
            $storageFile = $session->storageFile;

            if ($storageFile !== null) {
                $this->purger->removeStorageFileIfUnreferenced($storageFile);
            }

            return;
        }

        $disk = Storage::disk($session->disk);

        if ($disk->exists($session->path)) {
            $disk->delete($session->path);
        }
    }
}
