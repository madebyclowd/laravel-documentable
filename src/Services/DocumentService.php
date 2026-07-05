<?php

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use finfo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Exceptions\UnsupportedMultipartDriverException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Repositories\MultipartUploadRepository;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;

class DocumentService
{
    public function __construct(
        protected StorageFileRepository $storageFileRepository,
        protected DocumentRepository $documentRepository,
        protected MultipartUploadRepository $multipartUploadRepository
    ) {}

    /**
     * Upload a file and create a document record. Handles deduplication via file
     * hash and group-aware versioning.
     *
     * $documentGroupId: pass the group id of an existing document to upload a new
     * version *of that specific slot* (only meaningful when the type
     * `allows_multiple`). Omit it to version/replace the type's single slot
     * (allows_multiple = false) or to start a brand new independent slot
     * (allows_multiple = true).
     */
    public function upload(UploadedFile $file, DocumentType $type, Model $documentable, ?array $metadata = [], ?string $documentGroupId = null): Document
    {
        $this->validateFile($file, $type);

        return DB::transaction(function () use ($file, $type, $documentable, $metadata, $documentGroupId) {
            $hash = hash_file('sha256', $file->getRealPath());

            $storageFile = $this->getOrCreateStorageFile(
                $hash,
                $type->disk,
                fn () => $file->storeAs($type->path_prefix, (string) Str::uuid(), ['disk' => $type->disk]),
                (string) $file->getMimeType(),
                $file->getSize(),
                $metadata ?? []
            );

            return $this->createDocumentFromStorageFile(
                $storageFile,
                $type,
                $documentable,
                $file->getClientOriginalName(),
                $metadata ?? [],
                $documentGroupId
            );
        });
    }

    /**
     * Upload a file without associating it to any owner. The owner is assigned
     * later via the model's Documentable relation. This is a permanent, valid
     * state (not garbage) — lifecycle status distinguishing it from abandoned
     * uploads lands in phase 4.
     */
    public function uploadDetached(UploadedFile $file, DocumentType $type, ?array $metadata = []): Document
    {
        $this->validateFile($file, $type);

        return DB::transaction(function () use ($file, $type, $metadata) {
            $hash = hash_file('sha256', $file->getRealPath());

            $storageFile = $this->getOrCreateStorageFile(
                $hash,
                $type->disk,
                fn () => $file->storeAs($type->path_prefix, (string) Str::uuid(), ['disk' => $type->disk]),
                (string) $file->getMimeType(),
                $file->getSize(),
                $metadata ?? []
            );

            $groupId = (string) Str::uuid();

            return $this->documentRepository->create([
                'storage_file_id' => $storageFile->id,
                'document_type_id' => $type->id,
                'document_group_id' => $groupId,
                'documentable_type' => null,
                'documentable_id' => null,
                'client_filename' => $file->getClientOriginalName(),
                'metadata' => $metadata ?? [],
                'version' => 1,
                'is_latest' => true,
                'latest_marker' => $groupId,
            ]);
        });
    }

    /**
     * Generate a presigned URL for the document.
     */
    public function getUrl(Document $document, DateTimeInterface $expiration, string $disposition = 'inline'): string
    {
        $storageFile = $document->storageFile;

        // Strip characters that could break out of the header parameter — a raw
        // client-supplied filename in this position is a header-injection primitive.
        $filename = str_replace(['"', "\r", "\n"], '', $document->client_filename);

        return Storage::disk($storageFile->disk)->temporaryUrl(
            $storageFile->path,
            $expiration,
            [
                'ResponseContentDisposition' => $disposition.'; filename="'.$filename.'"',
            ]
        );
    }

    /**
     * Soft-delete a document. If it's currently the latest of its group, the
     * latest_marker unique-column must be cleared in the same operation —
     * otherwise a soft-deleted row would keep "occupying" the unique slot and
     * block a future upload from becoming latest for that group.
     */
    public function delete(Document $document): bool
    {
        return DB::transaction(function () use ($document) {
            if ($document->latest_marker !== null) {
                $document->update(['latest_marker' => null, 'is_latest' => false]);
            }

            return $this->documentRepository->delete($document);
        });
    }

    /**
     * Permanently delete a document and clean up its underlying storage file
     * if it is no longer referenced by any other documents.
     */
    public function purge(Document $document): bool
    {
        return DB::transaction(function () use ($document) {
            $storageFile = $document->storageFile;
            $storageFileId = $document->storage_file_id;
            $documentId = $document->id;

            $deleted = $this->documentRepository->forceDelete($document);

            if ($deleted && $storageFile) {
                $otherExists = Document::withTrashed()
                    ->where('storage_file_id', $storageFileId)
                    ->where('id', '!=', $documentId)
                    ->exists();

                if (! $otherExists) {
                    if (Storage::disk($storageFile->disk)->exists($storageFile->path)) {
                        Storage::disk($storageFile->disk)->delete($storageFile->path);
                    }
                    $this->storageFileRepository->delete($storageFile);
                }
            }

            return $deleted;
        });
    }

    protected function validateFile(UploadedFile $file, DocumentType $type): void
    {
        $this->validateSizeAndMime($file->getSize(), $file->getMimeType(), $type);
    }

    /**
     * The one entry point every transport funnels through — local UploadedFile
     * (upload()/uploadDetached()), multipart finalize, and direct-PUT finalize
     * all resolve size/mime first, then call this. Closes bugs.md #2/#3 (a
     * previous version let multipart skip validation and trusted a
     * client-asserted mime type).
     */
    protected function validateSizeAndMime(int $sizeBytes, ?string $mimeType, DocumentType $type): void
    {
        $maxBytes = $type->max_size_mb * 1024 * 1024;

        if ($sizeBytes > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => "File size exceeds limit of {$type->max_size_mb}MB.",
            ]);
        }

        if ($type->allowed_mimes && (! $mimeType || ! in_array($mimeType, $type->allowed_mimes, true))) {
            throw ValidationException::withMessages([
                'file' => "File type '{$mimeType}' is not allowed.",
            ]);
        }
    }

    /**
     * Begin a multipart upload session, scoped to $userId — only that user may
     * later request part URLs, complete, or abort it (ownership check pattern
     * kept from the reference implementation, it was already correct).
     *
     * @return array{upload_id: string, path: string, disk: string}
     */
    public function initiateMultipartUpload(string $originalFilename, DocumentType $type, string $userId): array
    {
        $path = $type->path_prefix.'/'.Str::uuid();

        $result = $this->resolveMultipartDriver($type->disk)->create($type->disk, $path, $originalFilename);

        $session = $this->multipartUploadRepository->create([
            'path' => $path,
            'upload_id' => $result['upload_id'],
            'user_id' => $userId,
            'document_type_id' => $type->id,
            'expires_at' => now()->addHours((int) config('documentable.multipart.session_ttl_hours', 24)),
        ]);

        return [
            'upload_id' => $session->upload_id,
            'path' => $session->path,
            'disk' => $type->disk,
        ];
    }

    /**
     * Presign one part's upload URL. TTL comes from config, not a hardcoded
     * literal (reference code hardcoded '+1 hour' inline).
     */
    public function generatePartUploadUrl(string $path, string $uploadId, string $userId, int $partNumber, DocumentType $type): string
    {
        $this->findOwnedSessionOrFail($path, $uploadId, $userId);

        return $this->resolveMultipartDriver($type->disk)
            ->presignPartUpload($type->disk, $path, $uploadId, $partNumber);
    }

    /**
     * Complete a multipart upload: resolve/reconcile parts per etag_strategy,
     * assemble on the provider, verify integrity independently of any ETag
     * (an ETag is never a real content checksum), then create the Document.
     *
     * $clientParts is only consulted when etag_strategy = 'client'; ignored
     * (server always calls listParts()) when 'server-authoritative'.
     */
    public function completeMultipartUpload(
        string $path,
        string $uploadId,
        string $userId,
        DocumentType $type,
        Model $documentable,
        string $originalFilename,
        ?array $clientParts = null,
        ?string $expectedHash = null,
        array $metadata = [],
        ?string $documentGroupId = null
    ): Document {
        $session = $this->findOwnedSessionOrFail($path, $uploadId, $userId);
        $driver = $this->resolveMultipartDriver($type->disk);

        $parts = $this->resolvePartsForCompletion($driver, $type->disk, $path, $uploadId, $clientParts);

        $driver->complete($type->disk, $path, $uploadId, $parts);

        $result = $this->hashAndDetectMime($type->disk, $path);

        $this->validateOrDelete($type->disk, $path, $result['size'], $result['mime'], $type);
        $this->verifyIntegrityOrDelete($type->disk, $path, $result['hash'], $expectedHash);

        return DB::transaction(function () use ($path, $type, $documentable, $originalFilename, $metadata, $result, $documentGroupId, $session) {
            $document = $this->createDocumentFromUploadedPath(
                $path,
                $type,
                $documentable,
                $originalFilename,
                $result,
                array_merge($metadata, ['multipart' => true]),
                $documentGroupId
            );

            $this->multipartUploadRepository->delete($session);

            return $document;
        });
    }

    /**
     * Ownership-gated abort. Deletes the session row after the driver
     * confirms the provider-side upload is aborted.
     */
    public function abortMultipartUpload(string $path, string $uploadId, string $userId, DocumentType $type): void
    {
        $session = $this->findOwnedSessionOrFail($path, $uploadId, $userId);

        $this->resolveMultipartDriver($type->disk)->abort($type->disk, $path, $uploadId);

        $this->multipartUploadRepository->delete($session);
    }

    /**
     * Presign a single PUT URL for a file under multipart.threshold_bytes —
     * avoids proxying small-file bytes through the app server, and avoids
     * multipart's per-part round-trip overhead for tiny files
     * (best-practices.md §1 — never force multipart below the threshold).
     *
     * @return array{url: string, headers: array, path: string, disk: string}
     */
    public function createPresignedUpload(DocumentType $type): array
    {
        $path = $type->path_prefix.'/'.Str::uuid();

        $ttl = now()->modify((string) config('documentable.multipart.part_upload_url_ttl', '+1 hour'));

        $signed = Storage::disk($type->disk)->temporaryUploadUrl($path, $ttl);

        return [
            'url' => $signed['url'],
            'headers' => $signed['headers'] ?? [],
            'path' => $path,
            'disk' => $type->disk,
        ];
    }

    /**
     * Finalize a direct presigned-PUT upload. Same shared validation +
     * hash-verification path as completeMultipartUpload() — mime is detected
     * from the actual downloaded bytes, never trusted from the client.
     */
    public function finalizeDirectUpload(
        string $path,
        DocumentType $type,
        Model $documentable,
        string $originalFilename,
        ?string $expectedHash = null,
        array $metadata = [],
        ?string $documentGroupId = null
    ): Document {
        if (! Storage::disk($type->disk)->exists($path)) {
            throw ValidationException::withMessages([
                'path' => 'No file found at the presigned upload path.',
            ]);
        }

        $result = $this->hashAndDetectMime($type->disk, $path);

        $this->validateOrDelete($type->disk, $path, $result['size'], $result['mime'], $type);
        $this->verifyIntegrityOrDelete($type->disk, $path, $result['hash'], $expectedHash);

        return DB::transaction(fn () => $this->createDocumentFromUploadedPath(
            $path,
            $type,
            $documentable,
            $originalFilename,
            $result,
            $metadata,
            $documentGroupId
        ));
    }

    protected function resolveMultipartDriver(string $disk): MultipartUploadDriver
    {
        $flysystemDriver = (string) config("filesystems.disks.{$disk}.driver");
        $driverClass = config("documentable.multipart.drivers.{$flysystemDriver}");

        if (! $driverClass) {
            throw UnsupportedMultipartDriverException::forDisk($disk, $flysystemDriver);
        }

        return app($driverClass);
    }

    protected function findOwnedSessionOrFail(string $path, string $uploadId, string $userId): MultipartUpload
    {
        $session = $this->multipartUploadRepository->findOwned($path, $uploadId, $userId);

        if (! $session) {
            throw ValidationException::withMessages([
                'upload_id' => 'Multipart upload session not found or not owned by this user.',
            ]);
        }

        return $session;
    }

    /**
     * client strategy: reconcile the client-reported part list against the
     * provider's authoritative listParts() before completing — fail fast on
     * a truncated/partial list instead of discovering it after a full
     * download+hash (bugs.md #7). server-authoritative strategy: always use
     * listParts(), client input is never consulted.
     */
    protected function resolvePartsForCompletion(MultipartUploadDriver $driver, string $disk, string $path, string $uploadId, ?array $clientParts): array
    {
        $strategy = config('documentable.multipart.etag_strategy', 'server-authoritative');

        if ($strategy === 'client' && ! empty($clientParts)) {
            $authoritative = $driver->listParts($disk, $path, $uploadId);

            $authoritativeNumbers = collect($authoritative)->pluck('PartNumber')->sort()->values()->all();
            $clientNumbers = collect($clientParts)->pluck('PartNumber')->sort()->values()->all();

            if ($authoritativeNumbers !== $clientNumbers) {
                throw ValidationException::withMessages([
                    'parts' => 'Reported parts do not match the parts actually uploaded to storage.',
                ]);
            }

            $parts = $clientParts;
        } else {
            $parts = $driver->listParts($disk, $path, $uploadId);
        }

        usort($parts, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

        return $parts;
    }

    /**
     * Stream the object once: sha256 over the whole body (integrity, always
     * independent of any provider ETag) and mime sniffed from the first chunk
     * via fileinfo (never a client-asserted mime_type field).
     *
     * @return array{hash: string, mime: string, size: int}
     */
    protected function hashAndDetectMime(string $disk, string $path): array
    {
        $stream = Storage::disk($disk)->readStream($path);

        $ctx = hash_init('sha256');
        $sizeBytes = 0;
        $firstChunk = null;

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);

            if ($firstChunk === null) {
                $firstChunk = $chunk;
            }

            hash_update($ctx, $chunk);
            $sizeBytes += strlen($chunk);
        }

        fclose($stream);

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($firstChunk ?? '') ?: 'application/octet-stream';

        return [
            'hash' => hash_final($ctx),
            'mime' => $mimeType,
            'size' => $sizeBytes,
        ];
    }

    /**
     * A failed size/mime check after the bytes are already assembled/written
     * (both multipart and direct-PUT land bytes before validation is
     * possible) must not leave an orphaned blob with no StorageFile/Document
     * pointing at it.
     */
    protected function validateOrDelete(string $disk, string $path, int $sizeBytes, ?string $mimeType, DocumentType $type): void
    {
        try {
            $this->validateSizeAndMime($sizeBytes, $mimeType, $type);
        } catch (ValidationException $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    protected function verifyIntegrityOrDelete(string $disk, string $path, string $actualHash, ?string $expectedHash): void
    {
        if ($expectedHash === null || hash_equals($expectedHash, $actualHash)) {
            return;
        }

        Storage::disk($disk)->delete($path);

        throw ValidationException::withMessages([
            'file' => "Integrity check failed. Server hash ({$actualHash}) does not match client hash ({$expectedHash}).",
        ]);
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
     */
    protected function createDocumentFromUploadedPath(
        string $path,
        DocumentType $type,
        Model $documentable,
        string $originalFilename,
        array $fileInfo,
        array $metadata,
        ?string $documentGroupId
    ): Document {
        $isDuplicate = $this->storageFileRepository->findByHash($fileInfo['hash']) !== null;

        $storageFile = $this->getOrCreateStorageFile(
            $fileInfo['hash'],
            $type->disk,
            fn () => $path,
            $fileInfo['mime'],
            $fileInfo['size'],
            $metadata
        );

        if ($isDuplicate) {
            Storage::disk($type->disk)->delete($path);
        }

        return $this->createDocumentFromStorageFile(
            $storageFile,
            $type,
            $documentable,
            $originalFilename,
            $metadata,
            $documentGroupId
        );
    }

    /**
     * Get existing StorageFile by hash or create a new one. $pathProvider is
     * executed ONLY if the file needs to be stored/registered.
     */
    protected function getOrCreateStorageFile(
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
     * Create a Document from an existing StorageFile, resolving which group/slot
     * it belongs to and whether it starts a new version chain or a fresh one:
     *
     * - $documentGroupId given: new version of that specific, already-existing
     *   group/slot (only meaningful when the type allows_multiple).
     * - $documentGroupId null + allows_multiple: start a brand new independent
     *   group/slot.
     * - $documentGroupId null + !allows_multiple: resolve the type's single slot
     *   (there can only ever be one group for this owner+type).
     *
     * Every created row is is_latest = true for its own group — allows_multiple
     * no longer forces is_latest = false, which is what made versioning silently
     * break for multi-document types before document_group_id existed.
     */
    protected function createDocumentFromStorageFile(
        StorageFile $storageFile,
        DocumentType $type,
        Model $documentable,
        string $clientFilename,
        array $metadata = [],
        ?string $documentGroupId = null
    ): Document {
        $metadata = array_merge([
            'size' => $storageFile->size_bytes,
            'mime_type' => $storageFile->mime_type,
        ], $metadata);

        $version = 1;
        $groupId = $documentGroupId;

        if ($groupId !== null) {
            $previousLatest = $this->documentRepository->findLatest(
                $documentable->getMorphClass(),
                (string) $documentable->getKey(),
                $type->id,
                $groupId
            );

            $version = $this->demoteAndResolveVersion($previousLatest, $type);
        } elseif ($type->allows_multiple) {
            $groupId = (string) Str::uuid();
        } else {
            $existing = $this->documentRepository->findLatest(
                $documentable->getMorphClass(),
                (string) $documentable->getKey(),
                $type->id
            );

            if ($existing) {
                $groupId = $existing->document_group_id;
                $version = $this->demoteAndResolveVersion($existing, $type);
            } else {
                $groupId = (string) Str::uuid();
            }
        }

        return $this->documentRepository->create([
            'storage_file_id' => $storageFile->id,
            'document_type_id' => $type->id,
            'document_group_id' => $groupId,
            'documentable_type' => $documentable->getMorphClass(),
            'documentable_id' => $documentable->getKey(),
            'client_filename' => $clientFilename,
            'metadata' => $metadata,
            'version' => $version,
            'is_latest' => true,
            'latest_marker' => $groupId,
        ]);
    }

    /**
     * Lock and demote the group's current latest document. Returns the version
     * number the new document should take: incremented if the type keeps
     * history, 1 if it hard-replaces (old row soft-deleted, no history kept).
     */
    protected function demoteAndResolveVersion(?Document $previousLatest, DocumentType $type): int
    {
        if (! $previousLatest) {
            return 1;
        }

        $locked = Document::whereKey($previousLatest->id)->lockForUpdate()->first();

        if (! $locked) {
            return 1;
        }

        $locked->update(['is_latest' => false, 'latest_marker' => null]);

        if ($type->requires_versioning) {
            return $locked->version + 1;
        }

        $this->documentRepository->delete($locked);

        return 1;
    }
}
