<?php

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;

class DocumentService
{
    public function __construct(
        protected StorageFileRepository $storageFileRepository,
        protected DocumentRepository $documentRepository
    ) {}

    /**
     * Upload a file and create a document record. Handles deduplication via file
     * hash and single-slot versioning (group-aware versioning lands in phase 2).
     */
    public function upload(UploadedFile $file, DocumentType $type, Model $documentable, ?array $metadata = []): Document
    {
        $this->validateFile($file, $type);

        return DB::transaction(function () use ($file, $type, $documentable, $metadata) {
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
                $metadata ?? []
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

            return $this->documentRepository->create([
                'storage_file_id' => $storageFile->id,
                'document_type_id' => $type->id,
                'documentable_type' => null,
                'documentable_id' => null,
                'client_filename' => $file->getClientOriginalName(),
                'metadata' => $metadata ?? [],
                'version' => 1,
                'is_latest' => ! $type->allows_multiple,
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
     * Soft-delete a document.
     */
    public function delete(Document $document): bool
    {
        return $this->documentRepository->delete($document);
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
        $maxBytes = $type->max_size_mb * 1024 * 1024;

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => "File size exceeds limit of {$type->max_size_mb}MB.",
            ]);
        }

        if ($type->allowed_mimes && ! in_array($file->getMimeType(), $type->allowed_mimes, true)) {
            throw ValidationException::withMessages([
                'file' => "File type '{$file->getMimeType()}' is not allowed.",
            ]);
        }
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
     * Create a Document from an existing StorageFile. Single-slot versioning
     * only — document_group_id-aware versioning lands in phase 2.
     */
    protected function createDocumentFromStorageFile(
        StorageFile $storageFile,
        DocumentType $type,
        Model $documentable,
        string $clientFilename,
        array $metadata = []
    ): Document {
        $metadata = array_merge([
            'size' => $storageFile->size_bytes,
            'mime_type' => $storageFile->mime_type,
        ], $metadata);

        $version = 1;

        if ($type->requires_versioning) {
            $previousLatest = $this->documentRepository->findLatest(
                $documentable->getMorphClass(),
                (string) $documentable->getKey(),
                $type->id
            );

            if ($previousLatest) {
                $version = $previousLatest->version + 1;
                $previousLatest->update(['is_latest' => false]);
            }
        } elseif (! $type->allows_multiple) {
            $existing = $this->documentRepository->findLatest(
                $documentable->getMorphClass(),
                (string) $documentable->getKey(),
                $type->id
            );

            if ($existing) {
                $existing->update(['is_latest' => false]);
                $this->documentRepository->delete($existing);
            }
        }

        return $this->documentRepository->create([
            'storage_file_id' => $storageFile->id,
            'document_type_id' => $type->id,
            'documentable_type' => $documentable->getMorphClass(),
            'documentable_id' => $documentable->getKey(),
            'client_filename' => $clientFilename,
            'metadata' => $metadata,
            'version' => $version,
            'is_latest' => ! $type->allows_multiple,
        ]);
    }
}
