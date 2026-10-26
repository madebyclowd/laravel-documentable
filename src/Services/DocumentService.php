<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentPurged;
use MadeByClowd\Documentable\Events\DocumentReassociated;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Events\DocumentVersionSuperseded;
use MadeByClowd\Documentable\Events\MultipartUploadAborted;
use MadeByClowd\Documentable\Events\MultipartUploadInitiated;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
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
        protected MultipartUploadRepository $multipartUploadRepository,
        protected ObjectVerifier $verifier,
        protected ResolvesDedupScope $dedupScopeResolver,
        protected GeneratesStoragePath $pathGenerator,
        protected ActorResolver $actor,
        protected UrlSigner $urlSigner
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
        $this->assertTypeActive($type);
        $this->validateFile($file, $type);

        return DB::transaction(function () use ($file, $type, $documentable, $metadata, $documentGroupId) {
            $hash = hash_file('sha256', $file->getRealPath());
            $dedupKey = $this->dedupScopeResolver->scopeKey($hash, $documentable);

            $storageFile = $this->getOrCreateStorageFile(
                $dedupKey,
                $type->disk,
                fn () => $this->storeUploadedFile($file, $type),
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
     * later via the model's Documentable relation.
     *
     * $pending: false (default) — permanent, valid state (not garbage), same as
     * before pending documents existed. true — temporary, expires after $ttlHours (or
     * config('documentable.lifecycle.pending_ttl_hours') if omitted) unless the
     * consumer calls $document->commit() before it lapses (e.g. once the real
     * owner is finally created and this document is reassociated to it).
     */
    public function uploadDetached(UploadedFile $file, DocumentType $type, ?array $metadata = [], bool $pending = false, ?int $ttlHours = null): Document
    {
        $this->assertTypeActive($type);
        $this->validateFile($file, $type);

        return DB::transaction(function () use ($file, $type, $metadata, $pending, $ttlHours) {
            $hash = hash_file('sha256', $file->getRealPath());
            $dedupKey = $this->dedupScopeResolver->scopeKey($hash, null);

            $storageFile = $this->getOrCreateStorageFile(
                $dedupKey,
                $type->disk,
                fn () => $this->storeUploadedFile($file, $type),
                (string) $file->getMimeType(),
                $file->getSize(),
                $metadata ?? []
            );

            $groupId = (string) Str::uuid();

            $expiresAt = null;

            if ($pending) {
                $expiresAt = now()->addHours($ttlHours ?? (int) config('documentable.lifecycle.pending_ttl_hours', 24));
            }

            $document = $this->documentRepository->create([
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
                'status' => $pending ? 'pending' : 'committed',
                'expires_at' => $expiresAt,
                'created_by' => $this->actor->idForAudit(),
            ]);

            Event::dispatch(new DocumentUploaded($document));

            return $document;
        });
    }

    /**
     * Generate a presigned URL for the document.
     */
    public function getUrl(Document $document, DateTimeInterface $expiration, string $disposition = 'inline'): string
    {
        return $this->urlSigner->sign($document, $expiration, $disposition);
    }

    /**
     * All latest-per-group documents for an owner, across every type unless
     * $documentTypeId narrows it. storageFile is eager-loaded. Doesn't consult
     * AuthorizesDocumentAccess — same seam boundary as every other DocumentService
     * method: authorization is a controller-layer concern, and this method has no
     * $user param to check it with.
     *
     * @return Collection<int, Document>
     */
    public function listForOwner(Model $documentable, ?string $documentTypeId = null): Collection
    {
        return $this->documentRepository->findAllLatestForOwnerGrouped(
            $documentable->getMorphClass(),
            (string) $documentable->getKey(),
            $documentTypeId
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
            $document->update([
                'latest_marker' => null,
                'is_latest' => false,
                'deleted_by' => $this->actor->idForAudit(),
            ]);

            $deleted = $this->documentRepository->delete($document);

            if ($deleted) {
                Event::dispatch(new DocumentDeleted($document));
            }

            return $deleted;
        });
    }

    /**
     * Permanently delete a document, then its stored blob when no other document references it.
     *
     * The document row is gone once this returns, whatever the result. `false` means the document could not be
     * deleted, or that it was but the blob could not: the `StorageFile` row is kept as the record of the
     * orphaned object, an error is logged, and `documents:clean-orphaned --storage-files` retries it. Disks
     * configured with `throw => false` report a failed delete as `false` rather than an exception, which is
     * why the result is checked rather than assumed.
     */
    public function purge(Document $document): bool
    {
        $storageFile = $document->storageFile;

        $deleted = $this->documentRepository->forceDelete($document);

        if (! $deleted) {
            return false;
        }

        $storageFileAlsoDeleted = false;

        if ($storageFile !== null) {
            $storageFileAlsoDeleted = $this->removeStorageFileIfUnreferenced($storageFile);
        }

        Event::dispatch(new DocumentPurged($document, $storageFileAlsoDeleted));

        return $storageFile === null || $storageFileAlsoDeleted || $this->isStorageFileReferenced($storageFile);
    }

    /**
     * Delete a stored blob and its `StorageFile` row if no document, trashed ones included, references it.
     * The check repeats under a row lock because a deduplicated upload may have attached a document to the
     * blob since the caller looked. Returns true when the blob and row are gone, false when the blob could
     * not be deleted (the row is kept so the next sweep can retry) or when the file is still referenced.
     */
    public function removeStorageFileIfUnreferenced(StorageFile $storageFile): bool
    {
        return DB::transaction(function () use ($storageFile) {
            $locked = StorageFile::whereKey($storageFile->id)->lockForUpdate()->first();

            if ($locked === null) {
                return true;
            }

            if ($this->isStorageFileReferenced($locked)) {
                return false;
            }

            if (! $this->deleteBlob($locked->disk, $locked->path)) {
                return false;
            }

            return $this->storageFileRepository->delete($locked);
        });
    }

    protected function isStorageFileReferenced(StorageFile $storageFile): bool
    {
        return Document::withTrashed()->where('storage_file_id', $storageFile->id)->exists();
    }

    /**
     * True when the object no longer exists on the disk afterwards, whether it was deleted now or was already
     * gone. A failed delete is logged with the disk and path, because the row that remembers it is the only
     * trace.
     */
    protected function deleteBlob(string $disk, string $path): bool
    {
        try {
            $filesystem = Storage::disk($disk);

            if (! $filesystem->exists($path)) {
                return true;
            }

            if ($filesystem->delete($path) !== false) {
                return true;
            }

            $reason = 'the disk reported the delete as failed';
        } catch (\Throwable $e) {
            $reason = $e->getMessage();
        }

        Log::error("Documentable could not delete [{$path}] from disk [{$disk}]: {$reason}. The storage file row is kept so documents:clean-orphaned --storage-files can retry.");

        return false;
    }

    /**
     * Move a document to a different owner, typically a detached upload that finally has its real owner.
     *
     * The move goes through the same slot and version resolution as an upload. For a single-slot type whose new
     * owner already holds a latest document, the moved document joins that slot's chain: it takes the group, the
     * previous latest is demoted (or soft-deleted when the type does not keep history) and the version is
     * incremented, so the owner never ends up with two "latest" documents. A multi-slot type keeps the moved
     * document in its own slot, and moving a document to the owner it already has changes nothing.
     */
    public function reassociateDocument(Document $document, Model $newOwner): Document
    {
        return DB::transaction(function () use ($document, $newOwner) {
            $previousOwner = null;

            if ($document->documentable_type && $document->documentable_id) {
                $morphClass = Relation::getMorphedModel($document->documentable_type) ?? $document->documentable_type;

                $previousOwner = $morphClass::find($document->documentable_id);
            }

            $morphType = $newOwner->getMorphClass();
            $ownerId = (string) $newOwner->getKey();

            $attributes = ['documentable_type' => $morphType, 'documentable_id' => $ownerId];
            $supersedes = null;

            $alreadyOwned = $document->documentable_type === $morphType && (string) $document->documentable_id === $ownerId;
            $type = DocumentType::withTrashed()->find($document->document_type_id);

            if (! $alreadyOwned && $document->is_latest && $type !== null && ! $type->allows_multiple) {
                $supersedes = $this->documentRepository->findLatest($morphType, $ownerId, $type->id);

                if ($supersedes !== null) {
                    $attributes['document_group_id'] = $supersedes->document_group_id;
                    $attributes['latest_marker'] = $supersedes->document_group_id;
                    $attributes['version'] = $this->demoteAndResolveVersion($supersedes, $type);
                }
            }

            $document->update($attributes);

            if ($supersedes !== null) {
                Event::dispatch(new DocumentVersionSuperseded($supersedes, $document));
            }

            Event::dispatch(new DocumentReassociated($document, $previousOwner));

            return $document;
        });
    }

    /**
     * @see UrlSigner::sanitizeHeaderFilename()
     */
    public static function sanitizeHeaderFilename(string $filename): string
    {
        return UrlSigner::sanitizeHeaderFilename($filename);
    }

    protected function validateFile(UploadedFile $file, DocumentType $type): void
    {
        $this->verifier->assertSizeAndMime($file->getSize(), $file->getMimeType(), $type);
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
        $this->assertTypeActive($type);

        $path = $this->pathGenerator->generate($type, $originalFilename);

        $result = $this->resolveMultipartDriver($type->disk)->create($type->disk, $path, $originalFilename);

        $session = $this->multipartUploadRepository->create([
            'path' => $path,
            'upload_id' => $result['upload_id'],
            'user_id' => $userId,
            'document_type_id' => $type->id,
            'expires_at' => now()->addHours((int) config('documentable.multipart.session_ttl_hours', 24)),
        ]);

        Event::dispatch(new MultipartUploadInitiated($session));

        return [
            'upload_id' => $session->upload_id,
            'path' => $session->path,
            'disk' => $type->disk,
        ];
    }

    /**
     * Authoritative "which parts actually landed on the bucket" for a resuming client —
     * diff this against local bookkeeping before re-uploading anything (feedback #7).
     *
     * @return array<int, array{PartNumber: int, ETag: string}>
     */
    public function listPartsForSession(string $path, string $uploadId, string $userId, DocumentType $type): array
    {
        $this->findOwnedSessionOrFail($path, $uploadId, $userId, $type);

        return $this->resolveMultipartDriver($type->disk)->listParts($type->disk, $path, $uploadId);
    }

    /**
     * Status check, not an ownership-gated lookup that throws on absence (feedback #8) —
     * session absence is an expected outcome here (reaped by documents:clean-orphaned, or
     * aborted), not an error condition. A resuming client branches on `exists` instead of
     * handling an exception for what is, for this one call, a normal case.
     *
     * @return array{exists: bool, expires_at: ?string, disk: ?string}
     */
    public function multipartSessionStatus(string $path, string $uploadId, string $userId): array
    {
        $session = $this->multipartUploadRepository->findOwned($path, $uploadId, $userId);

        if (! $session) {
            return ['exists' => false, 'expires_at' => null, 'disk' => null];
        }

        return [
            'exists' => true,
            'expires_at' => $session->expires_at?->toIso8601String(),
            'disk' => $session->documentType->disk,
        ];
    }

    /**
     * The caller's own multipart session, or null when there is none. Lets the controller layer authorize
     * against the session's document type before it answers a status probe.
     */
    public function findMultipartSession(string $path, string $uploadId, string $userId): ?MultipartUpload
    {
        return $this->multipartUploadRepository->findOwned($path, $uploadId, $userId);
    }

    /**
     * Presign one part's upload URL. TTL comes from config, not a hardcoded
     * literal (reference code hardcoded '+1 hour' inline).
     */
    public function generatePartUploadUrl(string $path, string $uploadId, string $userId, int $partNumber, DocumentType $type): string
    {
        $this->findOwnedSessionOrFail($path, $uploadId, $userId, $type);

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
        $this->assertTypeActive($type);

        $session = $this->findOwnedSessionOrFail($path, $uploadId, $userId, $type);
        $driver = $this->resolveMultipartDriver($type->disk);

        $parts = $this->resolvePartsForCompletion($driver, $type->disk, $path, $uploadId, $clientParts);

        $driver->complete($type->disk, $path, $uploadId, $parts);

        $result = $this->verifier->assembledObjectInfo($driver, $type->disk, $path);

        $this->deleteOnFailure($type->disk, $path, fn () => $this->verifier->assertSizeAndMime($result['size'], $result['mime'], $type));
        $this->deleteOnFailure($type->disk, $path, fn () => $this->verifier->assertIntegrity($result['hash'], $expectedHash));

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
     * Ownership-gated abort, for the user-facing API — a caller must prove
     * they own the session. Deletes the session row after the driver
     * confirms the provider-side upload is aborted.
     */
    public function abortMultipartUpload(string $path, string $uploadId, string $userId, DocumentType $type): void
    {
        $session = $this->findOwnedSessionOrFail($path, $uploadId, $userId, $type);

        $this->abortSession($session, $type->disk);
    }

    /**
     * Non-ownership-gated abort, for system-initiated cleanup (the
     * reaper) that already has the loaded MultipartUpload row in hand and has
     * no "user it's acting as" to re-verify against.
     */
    public function abortMultipartUploadSession(MultipartUpload $session): void
    {
        $this->abortSession($session, $session->documentType->disk);
    }

    protected function abortSession(MultipartUpload $session, string $disk): void
    {
        try {
            $this->resolveMultipartDriver($disk)->abort($disk, $session->path, $session->upload_id);
        } catch (MultipartUploadNotFoundException) {
            // Already gone on the provider: the abort has reached its goal, so only the row is left to remove.
        }

        $this->multipartUploadRepository->delete($session);

        Event::dispatch(new MultipartUploadAborted($session));
    }

    /**
     * Presign a single PUT URL for a file under multipart.threshold_bytes —
     * avoids proxying small-file bytes through the app server, and avoids
     * multipart's per-part round-trip overhead for tiny files
     * (never force multipart below the threshold).
     *
     * @return array{url: string, headers: array, path: string, disk: string}
     */
    public function createPresignedUpload(DocumentType $type, string $originalFilename): array
    {
        $this->assertTypeActive($type);

        $path = $this->pathGenerator->generate($type, $originalFilename);

        $ttl = now()->modify((string) config('documentable.multipart.part_upload_url_ttl', '+1 hour'));

        $signed = Storage::disk($type->disk)->temporaryUploadUrl($path, $ttl, $this->sseOptions($type->disk));

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
     *
     * The path is a client-supplied claim here, so this method never deletes the object it was pointed at
     * when validation or the integrity check fails. A rejected upload stays in the bucket until the bucket
     * lifecycle rule removes it.
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
        $this->assertTypeActive($type);

        if ($this->storageFileRepository->existsAtPath($type->disk, $path)) {
            throw ValidationException::withMessages([
                'path' => 'This path already backs a stored file and cannot be finalized again.',
            ]);
        }

        $this->assertPathIsWithinTypePrefix($type, $path);

        if (! Storage::disk($type->disk)->exists($path)) {
            throw ValidationException::withMessages([
                'path' => 'No file found at the presigned upload path.',
            ]);
        }

        $result = $this->verifier->hashAndDetectMime($type->disk, $path);

        $this->verifier->assertSizeAndMime($result['size'], $result['mime'], $type);
        $this->verifier->assertIntegrity($result['hash'], $expectedHash);

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

    /**
     * Opt-in (`security.enforce_path_prefix`): a presigned finalize may only name a key inside the type's
     * own prefix. Compared on a trailing slash so `invoices-archive/` is not mistaken for `invoices/`.
     */
    protected function assertPathIsWithinTypePrefix(DocumentType $type, string $path): void
    {
        if (! config('documentable.security.enforce_path_prefix', false)) {
            return;
        }

        if (! str_starts_with($path, rtrim($type->path_prefix, '/').'/')) {
            throw ValidationException::withMessages([
                'path' => 'The path is outside the storage prefix of this document type.',
            ]);
        }
    }

    /**
     * Soft-deleted DocumentTypes must not accept new uploads.
     * A FormRequest-level `Rule::exists(...)->whereNull('deleted_at')` check
     * is still recommended at the controller layer, but this is the
     * business rule living where it belongs regardless of caller — service
     * methods called directly (tests, jobs, artisan commands) get the same
     * protection a validated HTTP request would.
     */
    protected function assertTypeActive(DocumentType $type): void
    {
        if ($type->trashed()) {
            throw ValidationException::withMessages([
                'document_type_id' => 'This document type is no longer active.',
            ]);
        }
    }

    /**
     * Explicit SSE, passed through rather than relying on the bucket's
     * default encryption setting (security-risks.md #2).
     *
     * @return array{ServerSideEncryption?: string, SSEKMSKeyId?: string}
     */
    protected function sseOptions(string $disk): array
    {
        $sse = config("documentable.disks.{$disk}.server_side_encryption");

        if (! $sse) {
            return [];
        }

        $options = ['ServerSideEncryption' => $sse];

        if ($sse === 'aws:kms' && $kmsKeyId = config("documentable.disks.{$disk}.kms_key_id")) {
            $options['SSEKMSKeyId'] = $kmsKeyId;
        }

        return $options;
    }

    protected function storeUploadedFile(UploadedFile $file, DocumentType $type): string
    {
        $path = $this->pathGenerator->generate($type, $file->getClientOriginalName());

        return Storage::disk($type->disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            $this->sseOptions($type->disk)
        );
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

    /**
     * The caller's own session, which must also belong to `$type`: a session is started for one type (disk,
     * prefix, size limit, mime allow-list), and acting on it under another would sidestep those limits.
     */
    protected function findOwnedSessionOrFail(string $path, string $uploadId, string $userId, ?DocumentType $type = null): MultipartUpload
    {
        $session = $this->multipartUploadRepository->findOwned($path, $uploadId, $userId);

        if (! $session) {
            throw ValidationException::withMessages([
                'upload_id' => 'Multipart upload session not found or not owned by this user.',
            ]);
        }

        if ($type !== null && $session->document_type_id !== $type->id) {
            throw ValidationException::withMessages([
                'document_type_id' => 'This upload session was started for a different document type.',
            ]);
        }

        return $session;
    }

    /**
     * client strategy: reconcile the client-reported part list against the
     * provider's authoritative listParts() before completing — fail fast on
     * a truncated/partial list instead of discovering it after a full
     * download+hash. server-authoritative strategy: always use
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
     * A failed check after the bytes are already written (multipart assembly and direct PUT both land bytes
     * before validation is possible) must not leave an orphaned blob with no StorageFile or Document pointing
     * at it, so the object is deleted before the failure is rethrown.
     */
    protected function deleteOnFailure(string $disk, string $path, callable $check): void
    {
        try {
            $check();
        } catch (ValidationException $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
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
        $previousLatest = null;

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
            $previousLatest = $this->documentRepository->findLatest(
                $documentable->getMorphClass(),
                (string) $documentable->getKey(),
                $type->id
            );

            if ($previousLatest) {
                $groupId = $previousLatest->document_group_id;
                $version = $this->demoteAndResolveVersion($previousLatest, $type);
            } else {
                $groupId = (string) Str::uuid();
            }
        }

        $document = $this->documentRepository->create([
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
            'created_by' => $this->actor->idForAudit(),
        ]);

        if ($previousLatest) {
            Event::dispatch(new DocumentVersionSuperseded($previousLatest, $document));
        }

        Event::dispatch(new DocumentUploaded($document));

        return $document;
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
