<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
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
        private readonly DocumentAttacher $attacher,
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
     * Get existing StorageFile by hash or create a new one. $pathProvider is
     * executed ONLY if the file needs to be stored/registered.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function getOrCreateStorageFile(
        string $hash,
        string $disk,
        callable $pathProvider,
        string $mimeType,
        int $sizeBytes,
        array $metadata = []
    ): StorageFile {
        $storageFile = $this->storageFileRepository->findByHash($hash);

        if (! $storageFile) {
            $path = $pathProvider();

            $this->deleteOnFailure($disk, $path, fn () => $this->verifier->assertScanClean($disk, $path));

            $storageFile = $this->storageFileRepository->create([
                'file_hash' => $hash,
                'disk' => $disk,
                'path' => $path,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'metadata' => $metadata,
            ]);
        }

        return $storageFile;
    }

    /**
     * Register (or dedup-reuse) the StorageFile for a path that was already
     * written by the provider before the hash was known (multipart assembly
     * / a client PUT both land bytes before this point). On a dedup hit, the
     * just-written $path is a redundant duplicate of already-stored content
     * and is deleted — unlike upload()'s local-file path, this path can't
     * avoid the write up front, so it must clean up after the fact instead.
     *
     * @param  array{hash: string, mime: string, size: int}  $fileInfo
     * @param  array<string, mixed>  $metadata
     */
    public function registerUploadedPath(
        string $path,
        DocumentType $type,
        Model $documentable,
        string $originalFilename,
        array $fileInfo,
        array $metadata,
        ?string $documentGroupId
    ): Document {
        $dedupKey = $this->dedupScopeResolver->scopeKey($fileInfo['hash'], $documentable);
        $isDuplicate = $this->storageFileRepository->findByHash($dedupKey) !== null;

        $storageFile = $this->getOrCreateStorageFile(
            $dedupKey,
            $type->disk,
            fn () => $path,
            $fileInfo['mime'],
            $fileInfo['size'],
            $metadata
        );

        if ($isDuplicate) {
            Storage::disk($type->disk)->delete($path);
        }

        return $this->attacher->attachStorageFile(
            $storageFile,
            $type,
            $documentable,
            $originalFilename,
            $metadata,
            $documentGroupId
        );
    }
}
