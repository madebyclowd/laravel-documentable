<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;
use RuntimeException;

/**
 * Uploads that do not use a multipart session: the server-proxied `upload`, and the presigned single PUT with
 * its finalize step.
 *
 * @internal
 */
final class DirectUploadService
{
    public function __construct(
        private readonly StorageFileRepository $storageFileRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly ResolvesDedupScope $dedupScopeResolver,
        private readonly GeneratesStoragePath $pathGenerator,
        private readonly ObjectVerifier $verifier,
        private readonly StoredObjectRegistrar $registrar,
        private readonly DocumentAttacher $attacher,
        private readonly ActorResolver $actor,
        private readonly Config $config,
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
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function upload(UploadedFile $file, DocumentType $type, Model $documentable, ?array $metadata = [], ?string $documentGroupId = null): Document
    {
        UploadGuard::assertTypeActive($type);
        $this->verifier->assertSizeAndMime($file->getSize(), $file->getMimeType(), $type);

        return DB::transaction(function () use ($file, $type, $documentable, $metadata, $documentGroupId) {
            $hash = hash_file('sha256', $file->getRealPath()) ?: throw new RuntimeException('Could not hash the uploaded file.');
            $dedupKey = $this->dedupScopeResolver->scopeKey($hash, $documentable);

            $storageFile = $this->registrar->getOrCreateStorageFile(
                $dedupKey,
                $type->disk,
                fn () => $this->storeUploadedFile($file, $type),
                (string) $file->getMimeType(),
                $file->getSize(),
                $metadata ?? []
            );

            return $this->attacher->attachStorageFile(
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
     * $this->config->get('documentable.lifecycle.pending_ttl_hours') if omitted) unless the
     * consumer calls $document->commit() before it lapses (e.g. once the real
     * owner is finally created and this document is reassociated to it).
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function uploadDetached(UploadedFile $file, DocumentType $type, ?array $metadata = [], bool $pending = false, ?int $ttlHours = null): Document
    {
        UploadGuard::assertTypeActive($type);
        $this->verifier->assertSizeAndMime($file->getSize(), $file->getMimeType(), $type);

        return DB::transaction(function () use ($file, $type, $metadata, $pending, $ttlHours) {
            $hash = hash_file('sha256', $file->getRealPath()) ?: throw new RuntimeException('Could not hash the uploaded file.');
            $dedupKey = $this->dedupScopeResolver->scopeKey($hash, null);

            $storageFile = $this->registrar->getOrCreateStorageFile(
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
                $expiresAt = now()->addHours($ttlHours ?? (int) $this->config->get('documentable.lifecycle.pending_ttl_hours', 24));
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
     * Presign a single PUT URL for a file under multipart.threshold_bytes —
     * avoids proxying small-file bytes through the app server, and avoids
     * multipart's per-part round-trip overhead for tiny files
     * (never force multipart below the threshold).
     *
     * @return array{url: string, headers: array<string, mixed>, path: string, disk: string}
     */
    public function createPresignedUpload(DocumentType $type, string $originalFilename): array
    {
        UploadGuard::assertTypeActive($type);

        $path = $this->pathGenerator->generate($type, $originalFilename);

        $ttl = now()->modify((string) $this->config->get('documentable.multipart.part_upload_url_ttl', '+1 hour'));

        $signed = Storage::disk($type->disk)->temporaryUploadUrl($path, $ttl, ServerSideEncryption::options($type->disk));

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
     *
     * @param  array<string, mixed>  $metadata
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
        UploadGuard::assertTypeActive($type);

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

        return DB::transaction(fn () => $this->registrar->registerUploadedPath(
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
    private function assertPathIsWithinTypePrefix(DocumentType $type, string $path): void
    {
        if (! $this->config->get('documentable.security.enforce_path_prefix', false)) {
            return;
        }

        if (! str_starts_with($path, rtrim($type->path_prefix, '/').'/')) {
            throw ValidationException::withMessages([
                'path' => 'The path is outside the storage prefix of this document type.',
            ]);
        }
    }

    private function storeUploadedFile(UploadedFile $file, DocumentType $type): string
    {
        $path = $this->pathGenerator->generate($type, $file->getClientOriginalName());

        return Storage::disk($type->disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ServerSideEncryption::options($type->disk)
        ) ?: throw new RuntimeException("Could not store the uploaded file on disk [{$type->disk}].");
    }
}
