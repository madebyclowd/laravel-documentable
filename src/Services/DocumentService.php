<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;

/**
 * The public entry point for storing, reading and removing documents. Each method delegates to a focused
 * internal collaborator; only the public methods here follow semver. Protected members are not supported API.
 *
 * Every way of getting bytes in produces an upload session, and `attach()` is the only way a session becomes a
 * document: server upload with an owner (`upload`), server upload without one (`stageUpload`), presigned single
 * PUT (`initiatePresignedUpload` then `finalizeDirectUpload`) and multipart (`initiateMultipartUpload`, parts,
 * `completeMultipartUpload`).
 *
 * @api
 */
class DocumentService
{
    protected StorageFileRepository $storageFiles;

    protected VersionLifecycle $versionLifecycle;

    public function __construct(
        protected DocumentRepository $documentRepository,
        protected DirectUploadService $directUploads,
        protected MultipartUploadService $multipartUploads,
        protected UploadSessionLifecycle $sessionLifecycle,
        protected UrlSigner $urlSigner,
        protected DocumentPurger $purger,
        protected DocumentAttacher $attacher,
        ?StorageFileRepository $storageFiles = null,
        ?VersionLifecycle $versionLifecycle = null,
    ) {
        $this->storageFiles = $storageFiles ?? app(StorageFileRepository::class);
        $this->versionLifecycle = $versionLifecycle ?? app(VersionLifecycle::class);
    }

    /**
     * Upload a file for an owner and attach it in one call.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function upload(UploadedFile $file, DocumentType $type, Model $documentable, ?array $metadata = [], ?string $documentGroupId = null): Document
    {
        return $this->directUploads->upload($file, $type, $documentable, $metadata, $documentGroupId);
    }

    /**
     * Upload a file that has no owner yet. Returns its `uploaded` session; `attach()` it once the owner exists.
     */
    public function stageUpload(UploadedFile $file, DocumentType $type, ?string $userId = null): UploadSession
    {
        return $this->directUploads->stageUpload($file, $type, $userId);
    }

    /**
     * Start a presigned single PUT.
     *
     * @return array{session: UploadSession, url: string, headers: array<string, mixed>}
     */
    public function initiatePresignedUpload(DocumentType $type, string $filename, ?string $userId = null, ?UploadSessionData $declared = null): array
    {
        return $this->directUploads->initiatePresignedUpload($type, $filename, $userId, $declared);
    }

    /**
     * Check the object a client PUT for this session and mark it `uploaded`. Safe to call again.
     */
    public function finalizeDirectUpload(UploadSession $session, ?string $expectedHash = null): UploadSession
    {
        return $this->directUploads->finalizeDirectUpload($session, $expectedHash);
    }

    public function initiateMultipartUpload(DocumentType $type, string $filename, string $userId, ?UploadSessionData $declared = null): UploadSession
    {
        return $this->multipartUploads->initiate($type, $filename, $userId, $declared);
    }

    public function generatePartUploadUrl(UploadSession $session, int $partNumber): string
    {
        return $this->multipartUploads->presignPart($session, $partNumber);
    }

    /**
     * @return array<int, array{PartNumber: int, ETag: string}>
     */
    public function listPartsForSession(UploadSession $session): array
    {
        return $this->multipartUploads->listParts($session);
    }

    /**
     * @param  array<int, array{PartNumber: int, ETag: string}>|null  $clientParts
     */
    public function completeMultipartUpload(UploadSession $session, ?array $clientParts = null, ?string $expectedHash = null): UploadSession
    {
        return $this->multipartUploads->complete($session, $clientParts, $expectedHash);
    }

    /**
     * Cancel an open session and release what it holds.
     */
    public function abortUploadSession(UploadSession $session): UploadSession
    {
        return $this->sessionLifecycle->abort($session);
    }

    /**
     * End an open session that ran out of time. The reaper calls this.
     */
    public function expireUploadSession(UploadSession $session): UploadSession
    {
        return $this->sessionLifecycle->expire($session);
    }

    /**
     * Attach an `uploaded` session to its owner, creating the document.
     */
    public function attach(UploadSession $session, Model $owner, AttachOptions $options = new AttachOptions): Document
    {
        return $this->attacher->attach($session, $owner, $options);
    }

    /**
     * Find an existing verified storage file eligible for deduplication reuse under the current scope.
     */
    public function findReusable(string $hash, DocumentType $type, ?Authenticatable $user = null, ?Model $owner = null): ?StorageFile
    {
        return $this->storageFiles->findReusable($hash, $type, $user, $owner);
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
     * One page of `listForOwner()`, cut in SQL in a stable order. `total()` on the result counts every
     * latest-per-group document of the owner, whoever may view it.
     *
     * @return LengthAwarePaginator<int, Document>
     */
    public function pageForOwner(Model $documentable, ?string $documentTypeId, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->documentRepository->paginateLatestForOwnerGrouped(
            $documentable->getMorphClass(),
            (string) $documentable->getKey(),
            $documentTypeId,
            $perPage,
            $page
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
     * Restore a soft-deleted document.
     */
    public function restore(Document $document): Document
    {
        return $this->versionLifecycle->restore($document);
    }

    /**
     * Soft-delete every active version of a group in one transaction without promotion.
     */
    public function deleteGroup(string $groupId): int
    {
        return $this->purger->deleteGroup($groupId);
    }

    /**
     * Make a specific non-latest, non-trashed version the latest of its group.
     */
    public function promote(Document $document): Document
    {
        return $this->versionLifecycle->promote($document);
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
     * @see UrlSigner::sanitizeHeaderFilename()
     */
    public static function sanitizeHeaderFilename(string $filename): string
    {
        return UrlSigner::sanitizeHeaderFilename($filename);
    }
}
