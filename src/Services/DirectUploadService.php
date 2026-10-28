<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;
use RuntimeException;

/**
 * Uploads that do not use a multipart session: the server-proxied upload (with or without an owner) and the
 * presigned single PUT with its finalize step. Each one produces an upload session; only `attach()` turns a
 * session into a document.
 *
 * @internal
 */
final class DirectUploadService
{
    public function __construct(
        private readonly GeneratesStoragePath $pathGenerator,
        private readonly ObjectVerifier $verifier,
        private readonly StoredObjectRegistrar $registrar,
        private readonly DocumentAttacher $attacher,
        private readonly UploadFinalizer $finalizer,
        private readonly UploadSessionRepository $sessions,
        private readonly ActorResolver $actor,
        private readonly Config $config,
        private readonly DedupUserResolver $users = new DedupUserResolver,
    ) {}

    /**
     * Upload a file for an owner and attach it in one call. Handles deduplication via file hash and
     * group-aware versioning. The file is written once, and not at all when identical bytes are already stored.
     *
     * $documentGroupId: pass the group id of an existing document to upload a new version of that specific slot
     * (only meaningful when the type `allows_multiple`). Omit it to version the type's single slot or to start a
     * new independent slot.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function upload(UploadedFile $file, DocumentType $type, Model $documentable, ?array $metadata = [], ?string $documentGroupId = null, ?string $userId = null): Document
    {
        UploadGuard::assertTypeActive($type);
        $this->verifier->assertUploadRules($type, $file->getClientOriginalName(), (int) $file->getSize(), $this->headOf($file));

        return DB::transaction(function () use ($file, $type, $documentable, $metadata, $documentGroupId, $userId) {
            $hash = $this->hashOf($file);
            $user = $this->users->resolve($userId);

            $storageFile = $this->registrar->getOrCreateStorageFile(
                $hash,
                $type,
                $user,
                $documentable,
                fn () => $this->storeUploadedFile($file, $type),
                (string) $file->getMimeType(),
                $file->getSize(),
                $metadata ?? []
            );

            $session = $this->sessions->create([
                'user_id' => $userId ?? $this->actor->id(),
                'document_type_id' => $type->id,
                'disk' => $storageFile->disk,
                'kind' => UploadKind::Single,
                'client_filename' => $file->getClientOriginalName(),
                'path' => $storageFile->path,
                'status' => UploadSessionStatus::Uploaded,
                'storage_file_id' => $storageFile->id,
                'object_size' => $storageFile->size_bytes,
                'object_mime' => $storageFile->mime_type,
                'object_hash' => $hash,
                'expires_at' => $this->expiry(),
            ]);

            return $this->attacher->attach($session, $documentable, new AttachOptions($metadata ?? [], $documentGroupId));
        });
    }

    /**
     * Store an upload that has no owner yet and return its session, `uploaded`, to attach later. The object is
     * checked and scanned now; the stored file is registered at attach, when the dedup scope is known.
     */
    public function stageUpload(UploadedFile $file, DocumentType $type, ?string $userId = null): UploadSession
    {
        UploadGuard::assertTypeActive($type);
        $this->verifier->assertUploadRules($type, $file->getClientOriginalName(), (int) $file->getSize(), $this->headOf($file));

        $path = $this->storeUploadedFile($file, $type);

        $this->registrar->deleteOnFailure($type->disk, $path, fn () => $this->verifier->assertScanClean($type->disk, $path));

        return $this->sessions->create([
            'user_id' => $userId,
            'document_type_id' => $type->id,
            'disk' => $type->disk,
            'kind' => UploadKind::Single,
            'client_filename' => $file->getClientOriginalName(),
            'path' => $path,
            'status' => UploadSessionStatus::Uploaded,
            'object_size' => $file->getSize(),
            'object_mime' => (string) $file->getMimeType(),
            'object_hash' => $this->hashOf($file),
            'expires_at' => $this->expiry(),
        ]);
    }

    /**
     * Start a presigned single PUT. The path is generated here, never taken from the client, and the client
     * keeps only the session id.
     *
     * @return array{session: UploadSession, url: string, headers: array<string, mixed>}
     */
    public function initiatePresignedUpload(DocumentType $type, string $filename, ?string $userId = null, ?UploadSessionData $declared = null): array
    {
        UploadGuard::assertTypeActive($type);

        $path = $this->pathGenerator->generate($type, $filename);

        $ttl = now()->modify((string) $this->config->get('documentable.multipart.part_upload_url_ttl', '+1 hour'));

        $signed = Storage::disk($type->disk)->temporaryUploadUrl($path, $ttl, ServerSideEncryption::options($type->disk));

        $session = $this->sessions->create([
            'user_id' => $userId,
            'document_type_id' => $type->id,
            'disk' => $type->disk,
            'kind' => UploadKind::Single,
            'client_filename' => $filename,
            'path' => $path,
            'expires_at' => $this->expiry(),
        ] + ($declared ?? new UploadSessionData)->toColumns());

        return [
            'session' => $session,
            'url' => $signed['url'],
            'headers' => $signed['headers'] ?? [],
        ];
    }

    /**
     * Check the object a client PUT to the session's path and mark the session `uploaded`.
     *
     * @throws ValidationException
     */
    public function finalizeDirectUpload(UploadSession $session, ?string $expectedHash = null): UploadSession
    {
        return $this->finalizer->finalize($session, null, $expectedHash);
    }

    private function expiry(): Carbon
    {
        return now()->addHours((int) $this->config->get('documentable.multipart.session_ttl_hours', 24));
    }

    /**
     * The first bytes of the upload, which is all the type rules read.
     */
    private function headOf(UploadedFile $file): string
    {
        return (string) file_get_contents((string) $file->getRealPath(), false, null, 0, 8192);
    }

    private function hashOf(UploadedFile $file): string
    {
        return hash_file('sha256', (string) $file->getRealPath()) ?: throw new RuntimeException('Could not hash the uploaded file.');
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
