<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;

/**
 * Registers objects that already sit in storage: finds or creates the deduplicated `StorageFile`, scans a new
 * object before it is kept, and turns the result into a document. Shared by every transport that writes bytes
 * before the content hash is known.
 *
 * @internal
 */
final class StoredObjectRegistrar
{
    public function __construct(
        private readonly StorageFileRepository $storageFileRepository,
        private readonly ResolvesDedupScope $dedupScopeResolver,
        private readonly ObjectVerifier $verifier,
    ) {}

    /**
     * A failed check after the bytes are already written (multipart assembly and direct PUT both land bytes
     * before validation is possible) must not leave an orphaned blob with no StorageFile or Document pointing
     * at it, so the object is deleted before the failure is rethrown.
     */
    public function deleteOnFailure(string $disk, string $path, callable $check): void
    {
        try {
            $check();
        } catch (ValidationException $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * Get the existing StorageFile for this hash and disk or create a new one. $pathProvider is
     * executed ONLY if the file needs to be stored/registered.
     *
     * Two uploads of identical bytes can both miss the lookup. The unique `(file_hash, disk)` index lets only one
     * insert through; the other adopts the winner's stored file and deletes the copy it just wrote.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function getOrCreateStorageFile(
        string $hash,
        string $disk,
        callable $pathProvider,
        string $mimeType,
        int $sizeBytes,
        array $metadata = [],
        bool $scan = true
    ): StorageFile {
        $existing = $this->storageFileRepository->findByHash($hash, $disk, lock: true);

        if ($existing !== null) {
            return $existing;
        }

        $path = $pathProvider();

        if ($scan) {
            $this->deleteOnFailure($disk, $path, fn () => $this->verifier->assertScanClean($disk, $path));
        }

        $storageFile = $this->storageFileRepository->createOrFirst($hash, $disk, [
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => $sizeBytes,
            'metadata' => $metadata,
        ]);

        if ($storageFile->path !== $path) {
            Storage::disk($disk)->delete($path);
        }

        return $storageFile;
    }

    /**
     * The stored file behind a verified session object, found or created under the dedup scope of `$owner`.
     * The scope depends on the owner (a tenant, say), which is why this runs at attach and not at finalize.
     *
     * On a dedup hit the session's own object is a redundant copy and is deleted, unless the existing stored
     * file already points at that very path.
     */
    public function registerSessionObject(UploadSession $session, Model $owner): StorageFile
    {
        $dedupKey = $this->dedupScopeResolver->scopeKey((string) $session->object_hash, $owner);
        $existing = $this->storageFileRepository->findByHash($dedupKey, $session->disk, lock: true);

        $storageFile = $this->getOrCreateStorageFile(
            $dedupKey,
            $session->disk,
            fn () => $session->path,
            (string) $session->object_mime,
            (int) $session->object_size,
            [],
            scan: false
        );

        if ($existing !== null && $existing->path !== $session->path) {
            Storage::disk($session->disk)->delete($session->path);
        }

        return $storageFile;
    }
}
