<?php

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use finfo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentPurged;
use MadeByClowd\Documentable\Events\DocumentReassociated;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Events\DocumentVersionSuperseded;
use MadeByClowd\Documentable\Events\MultipartUploadAborted;
use MadeByClowd\Documentable\Events\MultipartUploadInitiated;
use MadeByClowd\Documentable\Exceptions\UnsupportedMultipartDriverException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentAccessLog;
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
        protected ScansUploadedFile $fileScanner,
        protected ResolvesDedupScope $dedupScopeResolver,
        protected GeneratesStoragePath $pathGenerator
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
     * before phase 4 existed. true — temporary, expires after $ttlHours (or
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
                'expires_at' => $pending
                    ? now()->addHours($ttlHours ?? (int) config('documentable.lifecycle.pending_ttl_hours', 24))
                    : null,
                'created_by' => $this->currentActorId(),
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
        $storageFile = $document->storageFile;

        $filename = static::sanitizeHeaderFilename($document->client_filename);

        $url = Storage::disk($storageFile->disk)->temporaryUrl(
            $storageFile->path,
            $expiration,
            [
                'ResponseContentDisposition' => $disposition.'; filename="'.$filename.'"',
            ]
        );

        $this->logAccess($document, $disposition === 'attachment' ? 'download' : 'view');

        return $url;
    }

    /**
     * All latest-per-group documents for an owner, across every type unless
     * $documentTypeId narrows it. storageFile eager-loaded (phase 8's fix, reused
     * here rather than reintroduced). Doesn't consult AuthorizesDocumentAccess —
     * same seam boundary as every other DocumentService method (see phase 5/7
     * notes: authorization is a controller-layer concern, this method has no
     * $user param to check it with).
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
     * Best-effort access log, config-gated (config('documentable.audit.access_log')) and off
     * by default — the package can't observe requests to a presigned URL directly, so
     * URL-generation time is the closest available proxy for "access".
     */
    protected function logAccess(Document $document, string $action): void
    {
        if (! config('documentable.audit.access_log', false)) {
            return;
        }

        DocumentAccessLog::create([
            'document_id' => $document->id,
            'actor_id' => $this->resolveActorId(),
            'action' => $action,
            'ip_address' => app()->bound('request') ? request()->ip() : null,
            'created_at' => now(),
        ]);
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
                'deleted_by' => $this->currentActorId(),
            ]);

            $deleted = $this->documentRepository->delete($document);

            if ($deleted) {
                Event::dispatch(new DocumentDeleted($document));
            }

            return $deleted;
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
            $storageFileAlsoDeleted = false;

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
                    $storageFileAlsoDeleted = true;
                }
            }

            if ($deleted) {
                Event::dispatch(new DocumentPurged($document, $storageFileAlsoDeleted));
            }

            return $deleted;
        });
    }

    /**
     * Move a document to a different owner (e.g. a detached upload finally
     * being attached to the real record it belongs to once that record
     * exists). $document keeps its group/version chain — only ownership
     * changes.
     */
    public function reassociateDocument(Document $document, Model $newOwner): Document
    {
        $previousOwner = null;

        if ($document->documentable_type && $document->documentable_id) {
            $morphClass = Relation::getMorphedModel($document->documentable_type) ?? $document->documentable_type;

            $previousOwner = $morphClass::find($document->documentable_id);
        }

        $document->update([
            'documentable_type' => $newOwner->getMorphClass(),
            'documentable_id' => $newOwner->getKey(),
        ]);

        Event::dispatch(new DocumentReassociated($document, $previousOwner));

        return $document;
    }

    /**
     * Strip every ASCII control character (not just the '"'/CR/LF originally
     * flagged) — a raw client-supplied filename interpolated into a
     * Content-Disposition header value is a header-injection primitive
     * (bugs.md #6). Public/static because it's a pure function useful to any
     * consumer building their own download endpoint against the same data.
     */
    public static function sanitizeHeaderFilename(string $filename): string
    {
        return preg_replace('/[\x00-\x1F\x7F"]/', '', $filename);
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
        $this->assertTypeActive($type);

        $session = $this->findOwnedSessionOrFail($path, $uploadId, $userId);
        $driver = $this->resolveMultipartDriver($type->disk);

        $parts = $this->resolvePartsForCompletion($driver, $type->disk, $path, $uploadId, $clientParts);

        $driver->complete($type->disk, $path, $uploadId, $parts);

        $result = $this->resolveAssembledFileInfo($driver, $type->disk, $path);

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
     * Ownership-gated abort, for the user-facing API — a caller must prove
     * they own the session. Deletes the session row after the driver
     * confirms the provider-side upload is aborted.
     */
    public function abortMultipartUpload(string $path, string $uploadId, string $userId, DocumentType $type): void
    {
        $session = $this->findOwnedSessionOrFail($path, $uploadId, $userId);

        $this->abortSession($session, $type->disk);
    }

    /**
     * Non-ownership-gated abort, for system-initiated cleanup (phase 4's
     * reaper) that already has the loaded MultipartUpload row in hand and has
     * no "user it's acting as" to re-verify against.
     */
    public function abortMultipartUploadSession(MultipartUpload $session): void
    {
        $this->abortSession($session, $session->documentType->disk);
    }

    protected function abortSession(MultipartUpload $session, string $disk): void
    {
        $this->resolveMultipartDriver($disk)->abort($disk, $session->path, $session->upload_id);

        $this->multipartUploadRepository->delete($session);

        Event::dispatch(new MultipartUploadAborted($session));
    }

    /**
     * Presign a single PUT URL for a file under multipart.threshold_bytes —
     * avoids proxying small-file bytes through the app server, and avoids
     * multipart's per-part round-trip overhead for tiny files
     * (best-practices.md §1 — never force multipart below the threshold).
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

    /**
     * Soft-deleted DocumentTypes must not accept new uploads (bugs.md #4).
     * A FormRequest-level `Rule::exists(...)->whereNull('deleted_at')` check
     * is still recommended at the phase 7 controller layer, but this is the
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

    /**
     * Actor tracking is opt-in (config('documentable.audit.enabled')) and best-effort:
     * null when disabled, or when there's no authenticated actor in the current
     * context (CLI, queued jobs). Never a hard requirement to upload/delete.
     */
    protected function currentActorId(): ?string
    {
        if (! config('documentable.audit.enabled', false)) {
            return null;
        }

        return $this->resolveActorId();
    }

    protected function resolveActorId(): ?string
    {
        $id = Auth::id();

        return $id !== null ? (string) $id : null;
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
     * Optional fast path (missing-features.md, best-practices.md §4b): when
     * use_native_checksum is enabled and the driver can report a provider-computed
     * full-object checksum, skip the full re-download-and-hash — only the first
     * chunk is read (for mime sniffing) and size comes from a HeadObject-equivalent
     * call. Falls back to the full-stream hash whenever the driver returns null
     * (unsupported backend, or feature disabled) — this is never the only
     * integrity path.
     *
     * @return array{hash: string, mime: string, size: int}
     */
    protected function resolveAssembledFileInfo(MultipartUploadDriver $driver, string $disk, string $path): array
    {
        if (config('documentable.multipart.use_native_checksum', false)) {
            $checksum = $driver->retrieveChecksum($disk, $path);

            if ($checksum !== null) {
                return [
                    'hash' => $checksum,
                    'mime' => $this->detectMimeFromHead($disk, $path),
                    'size' => Storage::disk($disk)->size($path),
                ];
            }
        }

        return $this->hashAndDetectMime($disk, $path);
    }

    /**
     * Sniff mime from only the first chunk of the object — cheap regardless of
     * object size, since Storage::readStream() is lazy and this never drains it.
     */
    protected function detectMimeFromHead(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        $chunk = fread($stream, 8192);
        fclose($stream);

        return (new finfo(FILEINFO_MIME_TYPE))->buffer($chunk ?: '') ?: 'application/octet-stream';
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

    /**
     * ScansUploadedFile hook (security-risks.md #1) — default is a no-op
     * returning Clean, but always runs so wiring a real scanner in later
     * doesn't require touching call sites.
     */
    protected function scanOrDelete(string $disk, string $path): void
    {
        if (! $this->fileScanner->scan($disk, $path)->isInfected()) {
            return;
        }

        Storage::disk($disk)->delete($path);

        throw ValidationException::withMessages([
            'file' => 'Uploaded file failed a security scan.',
        ]);
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

            $this->scanOrDelete($disk, $path);

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
            'created_by' => $this->currentActorId(),
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
