<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use LogicException;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Events\MultipartUploadAborted;
use MadeByClowd\Documentable\Events\MultipartUploadInitiated;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Exceptions\UnsupportedMultipartDriverException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Repositories\MultipartUploadRepository;

/**
 * Chunked uploads through a provider multipart session: initiate, part URLs, resume, complete and abort. A
 * session belongs to one user and one document type, and only that user may act on it.
 *
 * @internal
 */
final class MultipartUploadService
{
    public function __construct(
        private readonly MultipartUploadRepository $multipartUploadRepository,
        private readonly GeneratesStoragePath $pathGenerator,
        private readonly ObjectVerifier $verifier,
        private readonly StoredObjectRegistrar $registrar,
        private readonly Config $config,
        private readonly Container $container,
    ) {}

    /**
     * Begin a multipart upload session, scoped to $userId — only that user may
     * later request part URLs, complete, or abort it (ownership check pattern
     * kept from the reference implementation, it was already correct).
     *
     * @return array{upload_id: string, path: string, disk: string}
     */
    public function initiateMultipartUpload(string $originalFilename, DocumentType $type, string $userId): array
    {
        UploadGuard::assertTypeActive($type);

        $path = $this->pathGenerator->generate($type, $originalFilename);

        $result = $this->resolveMultipartDriver($type->disk)->create($type->disk, $path, $originalFilename);

        $session = $this->multipartUploadRepository->create([
            'path' => $path,
            'upload_id' => $result['upload_id'],
            'user_id' => $userId,
            'document_type_id' => $type->id,
            'expires_at' => now()->addHours((int) $this->config->get('documentable.multipart.session_ttl_hours', 24)),
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
            'disk' => $this->diskOf($session),
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
        UploadGuard::assertTypeActive($type);

        $session = $this->findOwnedSessionOrFail($path, $uploadId, $userId, $type);
        $driver = $this->resolveMultipartDriver($type->disk);

        $parts = $this->resolvePartsForCompletion($driver, $type->disk, $path, $uploadId, $clientParts);

        $driver->complete($type->disk, $path, $uploadId, $parts);

        $result = $this->verifier->assembledObjectInfo($driver, $type->disk, $path);

        $this->registrar->deleteOnFailure($type->disk, $path, fn () => $this->verifier->assertSizeAndMime($result['size'], $result['mime'], $type));
        $this->registrar->deleteOnFailure($type->disk, $path, fn () => $this->verifier->assertIntegrity($result['hash'], $expectedHash));

        return DB::transaction(function () use ($path, $type, $documentable, $originalFilename, $metadata, $result, $documentGroupId, $session) {
            $document = $this->registrar->registerUploadedPath(
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
        $this->abortSession($session, $this->diskOf($session));
    }

    private function abortSession(MultipartUpload $session, string $disk): void
    {
        try {
            $this->resolveMultipartDriver($disk)->abort($disk, $session->path, $session->upload_id);
        } catch (MultipartUploadNotFoundException) {
            // Already gone on the provider: the abort has reached its goal, so only the row is left to remove.
        }

        $this->multipartUploadRepository->delete($session);

        Event::dispatch(new MultipartUploadAborted($session));
    }

    private function resolveMultipartDriver(string $disk): MultipartUploadDriver
    {
        $flysystemDriver = (string) $this->config->get("filesystems.disks.{$disk}.driver");
        $driverClass = $this->config->get("documentable.multipart.drivers.{$flysystemDriver}");

        if (! $driverClass) {
            throw UnsupportedMultipartDriverException::forDisk($disk, $flysystemDriver);
        }

        return $this->container->make($driverClass);
    }

    /**
     * The caller's own session, which must also belong to `$type`: a session is started for one type (disk,
     * prefix, size limit, mime allow-list), and acting on it under another would sidestep those limits.
     */
    private function findOwnedSessionOrFail(string $path, string $uploadId, string $userId, ?DocumentType $type = null): MultipartUpload
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
     *
     * @param  array<int, array{PartNumber: int, ETag: string}>|null  $clientParts
     * @return array<int, array{PartNumber: int, ETag: string}>
     */
    private function resolvePartsForCompletion(MultipartUploadDriver $driver, string $disk, string $path, string $uploadId, ?array $clientParts): array
    {
        $strategy = $this->config->get('documentable.multipart.etag_strategy', 'server-authoritative');

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

    private function diskOf(MultipartUpload $session): string
    {
        $type = $session->documentType;

        if ($type === null) {
            throw new LogicException("Multipart session {$session->id} has no document type.");
        }

        return $type->disk;
    }
}
