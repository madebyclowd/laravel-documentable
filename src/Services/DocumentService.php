<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Repositories\DocumentRepository;

/**
 * The public entry point for storing, reading and removing documents. Each method delegates to a focused
 * internal collaborator; only the public methods here follow semver. Protected members are not supported API.
 *
 * @api
 */
class DocumentService
{
    public function __construct(
        protected DocumentRepository $documentRepository,
        protected DirectUploadService $directUploads,
        protected MultipartUploadService $multipartUploads,
        protected UrlSigner $urlSigner,
        protected DocumentPurger $purger,
        protected DocumentAttacher $attacher
    ) {}

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
     * @see DocumentPurger::delete()
     */
    public function delete(Document $document): bool
    {
        return $this->purger->delete($document);
    }

    /**
     * @see DocumentPurger::purge()
     */
    public function purge(Document $document): bool
    {
        return $this->purger->purge($document);
    }

    /**
     * @see DocumentPurger::removeStorageFileIfUnreferenced()
     */
    public function removeStorageFileIfUnreferenced(StorageFile $storageFile): bool
    {
        return $this->purger->removeStorageFileIfUnreferenced($storageFile);
    }

    /**
     * @see DocumentAttacher::reassociate()
     */
    public function reassociateDocument(Document $document, Model $newOwner): Document
    {
        return $this->attacher->reassociate($document, $newOwner);
    }

    /**
     * @see UrlSigner::sanitizeHeaderFilename()
     */
    public static function sanitizeHeaderFilename(string $filename): string
    {
        return UrlSigner::sanitizeHeaderFilename($filename);
    }

    /**
     * @see DocumentAttacher::attachStorageFile()
     */
    protected function createDocumentFromStorageFile(
        StorageFile $storageFile,
        DocumentType $type,
        Model $documentable,
        string $clientFilename,
        array $metadata = [],
        ?string $documentGroupId = null
    ): Document {
        return $this->attacher->attachStorageFile($storageFile, $type, $documentable, $clientFilename, $metadata, $documentGroupId);
    }

    /**
     * @see DirectUploadService::upload()
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function upload(UploadedFile $file, DocumentType $type, Model $documentable, ?array $metadata = [], ?string $documentGroupId = null): Document
    {
        return $this->directUploads->upload($file, $type, $documentable, $metadata, $documentGroupId);
    }

    /**
     * @see DirectUploadService::uploadDetached()
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function uploadDetached(UploadedFile $file, DocumentType $type, ?array $metadata = [], bool $pending = false, ?int $ttlHours = null): Document
    {
        return $this->directUploads->uploadDetached($file, $type, $metadata, $pending, $ttlHours);
    }

    /**
     * @see DirectUploadService::createPresignedUpload()
     *
     * @return array{url: string, headers: array<string, mixed>, path: string, disk: string}
     */
    public function createPresignedUpload(DocumentType $type, string $originalFilename): array
    {
        return $this->directUploads->createPresignedUpload($type, $originalFilename);
    }

    /**
     * @see DirectUploadService::finalizeDirectUpload()
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
        return $this->directUploads->finalizeDirectUpload($path, $type, $documentable, $originalFilename, $expectedHash, $metadata, $documentGroupId);
    }

    /**
     * @see MultipartUploadService::initiateMultipartUpload()
     *
     * @return array{upload_id: string, path: string, disk: string}
     */
    public function initiateMultipartUpload(string $originalFilename, DocumentType $type, string $userId): array
    {
        return $this->multipartUploads->initiateMultipartUpload($originalFilename, $type, $userId);
    }

    /**
     * @see MultipartUploadService::listPartsForSession()
     *
     * @return array<int, array{PartNumber: int, ETag: string}>
     */
    public function listPartsForSession(string $path, string $uploadId, string $userId, DocumentType $type): array
    {
        return $this->multipartUploads->listPartsForSession($path, $uploadId, $userId, $type);
    }

    /**
     * @see MultipartUploadService::multipartSessionStatus()
     *
     * @return array{exists: bool, expires_at: ?string, disk: ?string}
     */
    public function multipartSessionStatus(string $path, string $uploadId, string $userId): array
    {
        return $this->multipartUploads->multipartSessionStatus($path, $uploadId, $userId);
    }

    /**
     * @see MultipartUploadService::findMultipartSession()
     */
    public function findMultipartSession(string $path, string $uploadId, string $userId): ?MultipartUpload
    {
        return $this->multipartUploads->findMultipartSession($path, $uploadId, $userId);
    }

    /**
     * @see MultipartUploadService::generatePartUploadUrl()
     */
    public function generatePartUploadUrl(string $path, string $uploadId, string $userId, int $partNumber, DocumentType $type): string
    {
        return $this->multipartUploads->generatePartUploadUrl($path, $uploadId, $userId, $partNumber, $type);
    }

    /**
     * @see MultipartUploadService::completeMultipartUpload()
     *
     * @param  array<int, array{PartNumber: int, ETag: string}>|null  $clientParts
     * @param  array<string, mixed>  $metadata
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
        return $this->multipartUploads->completeMultipartUpload($path, $uploadId, $userId, $type, $documentable, $originalFilename, $clientParts, $expectedHash, $metadata, $documentGroupId);
    }

    /**
     * @see MultipartUploadService::abortMultipartUpload()
     */
    public function abortMultipartUpload(string $path, string $uploadId, string $userId, DocumentType $type): void
    {
        $this->multipartUploads->abortMultipartUpload($path, $uploadId, $userId, $type);
    }

    /**
     * @see MultipartUploadService::abortMultipartUploadSession()
     */
    public function abortMultipartUploadSession(MultipartUpload $session): void
    {
        $this->multipartUploads->abortMultipartUploadSession($session);
    }
}
