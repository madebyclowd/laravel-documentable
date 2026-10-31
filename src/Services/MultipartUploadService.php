<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Events\MultipartUploadInitiated;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;

/**
 * Chunked uploads through a provider multipart session: initiate, part URLs, resume and complete. The session
 * is bound to one user and one document type when it starts; callers hold its id and nothing else.
 *
 * @internal
 */
final class MultipartUploadService
{
    public function __construct(
        private readonly UploadSessionRepository $sessions,
        private readonly GeneratesStoragePath $pathGenerator,
        private readonly MultipartDriverResolver $drivers,
        private readonly UploadFinalizer $finalizer,
        private readonly Config $config,
    ) {}

    /**
     * Begin a multipart upload session scoped to $userId.
     */
    public function initiate(DocumentType $type, string $filename, string $userId, ?UploadSessionData $declared = null): UploadSession
    {
        UploadGuard::assertTypeActive($type);

        $path = $this->pathGenerator->generate($type, $filename);

        $result = $this->drivers->forDisk($type->disk)->create($type->disk, $path, $filename);

        $session = $this->sessions->create([
            'user_id' => $userId,
            'document_type_id' => $type->id,
            'disk' => $type->disk,
            'kind' => UploadKind::Multipart,
            'client_filename' => $filename,
            'path' => $path,
            'upload_id' => $result['upload_id'],
            'expires_at' => now()->addHours((int) $this->config->get('documentable.multipart.session_ttl_hours', 24)),
        ] + ($declared ?? new UploadSessionData)->toColumns());

        Event::dispatch(new MultipartUploadInitiated($session));

        return $session;
    }

    /**
     * Presign one part's upload URL.
     */
    public function presignPart(UploadSession $session, int $partNumber): string
    {
        $this->assertOpenMultipart($session);

        return $this->drivers->forDisk($session->disk)
            ->presignPartUpload($session, $partNumber);
    }

    /**
     * The provider's authoritative list of parts that actually landed, for a resuming client.
     *
     * @return array<int, array{PartNumber: int, ETag: string}>
     */
    public function listParts(UploadSession $session): array
    {
        $this->assertOpenMultipart($session);

        return $this->drivers->forDisk($session->disk)->listParts($session);
    }

    /**
     * Assemble the parts on the provider, then check the object like any other upload.
     *
     * $clientParts is only consulted when etag_strategy = 'client'; ignored (the server always calls
     * listParts()) when 'server-authoritative'.
     *
     * @param  array<int, array{PartNumber: int, ETag: string}>|null  $clientParts
     *
     * @throws ValidationException
     */
    public function complete(UploadSession $session, ?array $clientParts = null, ?string $expectedHash = null): UploadSession
    {
        if ($session->status === UploadSessionStatus::Uploaded || $session->status === UploadSessionStatus::Attached) {
            return $session;
        }

        $this->assertOpenMultipart($session);

        $driver = $this->drivers->forDisk($session->disk);

        $parts = $this->resolvePartsForCompletion($driver, $session, $clientParts);

        $driver->complete($session, $parts);

        return $this->finalizer->finalize($session, null, $expectedHash);
    }

    private function assertOpenMultipart(UploadSession $session): void
    {
        if ($session->kind !== UploadKind::Multipart) {
            throw ValidationException::withMessages(['session_id' => 'This upload session is not a multipart upload.']);
        }

        if ($session->status !== UploadSessionStatus::Initiated) {
            throw ValidationException::withMessages(['session_id' => 'This upload session can no longer be used.']);
        }

        $this->finalizer->assertUsable($session);
    }

    /**
     * client strategy: reconcile the client-reported part list against the provider's authoritative
     * listParts() before completing, and fail fast on a truncated list. server-authoritative strategy: always
     * use listParts(); client input is never consulted.
     *
     * @param  array<int, array{PartNumber: int, ETag: string}>|null  $clientParts
     * @return array<int, array{PartNumber: int, ETag: string}>
     */
    private function resolvePartsForCompletion(MultipartUploadDriver $driver, UploadSession $session, ?array $clientParts): array
    {
        $strategy = $this->config->get('documentable.multipart.etag_strategy', 'server-authoritative');

        if ($strategy === 'client' && ! empty($clientParts)) {
            $authoritative = $driver->listParts($session);

            $authoritativeNumbers = collect($authoritative)->pluck('PartNumber')->sort()->values()->all();
            $clientNumbers = collect($clientParts)->pluck('PartNumber')->sort()->values()->all();

            if ($authoritativeNumbers !== $clientNumbers) {
                throw ValidationException::withMessages([
                    'parts' => 'Reported parts do not match the parts actually uploaded to storage.',
                ]);
            }

            $parts = $clientParts;
        } else {
            $parts = $driver->listParts($session);
        }

        usort($parts, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);

        return $parts;
    }
}
