<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Events\MultipartUploadAborted;
use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Models\UploadSession;
use Throwable;

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
        return DB::transaction(function () use ($session, $to) {
            /** @var UploadSession $locked */
            $locked = UploadSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            try {
                $locked->status->transitionTo($to);
            } catch (IllegalUploadSessionTransition) {
                return $locked;
            }

            // A provider upload still open is aborted before the state changes, so a provider that cannot be reached
            // leaves the session open for the next sweep to retry.
            if ($this->holdsOpenProviderUpload($locked)) {
                $this->abortProviderUpload($locked);
            }

            // Invariant: a session row is never Expired while its storage object remains.
            // Attach-race invariant: check + delete + status update happens inside lockForUpdate,
            // only proceeding if storage_file_id is still null.
            if ($locked->storage_file_id === null) {
                $this->discardOrphanedObject($locked);
            }

            $locked->forceFill(['status' => $to])->save();

            if ($storageFile = $locked->storageFile) {
                $this->purger->removeStorageFileIfUnreferenced($storageFile);
            }

            if ($locked->kind === UploadKind::Multipart) {
                Event::dispatch(new MultipartUploadAborted($locked));
            }

            return $locked;
        });
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
            $this->drivers->forDisk($session->disk)->abort($session);
        } catch (MultipartUploadNotFoundException) {
            // Already gone on the provider: the abort has reached its goal.
        }
    }

    /**
     * Deletes the object a never-attached session may have left. A failure bubbles up, rolling back the transaction
     * so the row stays open for the next run.
     */
    private function discardOrphanedObject(UploadSession $session): void
    {
        $client = S3ClientDetector::client($session->disk);

        if ($client === null) {
            $this->deleteFromDisk($session);

            return;
        }

        $this->deleteFromBucket($client, $session);
    }

    /**
     * Native HeadObject first, so a 404 counts as already gone. `Storage::exists()` would answer false for any
     * error on a disk with `throw` off, hiding a real failure.
     */
    private function deleteFromBucket(S3Client $client, UploadSession $session): void
    {
        $object = ['Bucket' => S3ClientDetector::bucket($session->disk), 'Key' => $session->path];

        try {
            $client->headObject($object);
        } catch (Throwable $e) {
            if (S3ExceptionMapper::isNotFoundOrGone($e)) {
                return;
            }

            throw $e;
        }

        $client->deleteObject($object);
    }

    private function deleteFromDisk(UploadSession $session): void
    {
        $disk = Storage::disk($session->disk);

        if ($disk->exists($session->path)) {
            $disk->delete($session->path);
        }
    }
}
