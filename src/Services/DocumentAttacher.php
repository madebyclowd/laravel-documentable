<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Events\DocumentVersionSuperseded;
use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;
use MadeByClowd\Documentable\Exceptions\SlotConflict;
use MadeByClowd\Documentable\Jobs\VerifyUploadedObject;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;

/**
 * Puts a stored file into an owner's slot: resolves the document group, demotes or replaces the previous
 * latest, bumps the version, and announces it. Every transport (server upload, single PUT, multipart, attach later)
 * goes through here so a slot can never end up with two latest documents.
 *
 * @internal
 */
final class DocumentAttacher
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly UploadSessionRepository $sessions,
        private readonly StoredObjectRegistrar $registrar,
        private readonly UploadFinalizer $finalizer,
        private readonly ActorResolver $actor,
        private readonly DocumentableConfig $config,
    ) {}

    /**
     * Attach an uploaded session to its owner. This is the one place a slot is locked, demoted and versioned, so
     * the "exactly one latest document per owner, type and group" invariant holds whatever the transport.
     *
     * The session moves to `attached` first, under a row lock, so two attach calls on the same session cannot
     * both succeed. Everything after that runs in the same transaction: if registering the file or creating the
     * document fails, the session is back to `uploaded`.
     *
     * @throws ValidationException when the session cannot be attached: not uploaded yet, expired, already
     *                             attached, or the requested group is not the owner's
     */
    public function attach(UploadSession $session, Model $owner, AttachOptions $options = new AttachOptions): Document
    {
        $type = $session->documentTypeOrFail();

        UploadGuard::assertTypeActive($type);

        if ($session->status === UploadSessionStatus::Uploaded) {
            $this->finalizer->assertUsable($session);
        }

        $this->assertGroupBelongsToOwner($options->documentGroupId, $owner, $type);

        return DB::transaction(function () use ($session, $owner, $options, $type) {
            try {
                $locked = $this->sessions->markAttached($session, $owner);
            } catch (IllegalUploadSessionTransition) {
                throw ValidationException::withMessages(['session_id' => 'This upload session is not ready to be attached, or was already attached.']);
            }

            $storageFile = $locked->storageFile ?? $this->registrar->registerSessionObject($locked, $owner);

            if ($locked->storage_file_id === null) {
                $locked->forceFill(['storage_file_id' => $storageFile->id])->save();
            }

            $document = $this->attachStorageFile(
                $storageFile,
                $type,
                $owner,
                $locked->client_filename,
                $options->metadata,
                $options->documentGroupId,
                $locked->isVerified() ? DocumentStatus::Committed : DocumentStatus::Processing
            );

            if ($document->status === DocumentStatus::Processing) {
                VerifyUploadedObject::dispatch($document->id)
                    ->onConnection($this->config->verificationConnection)
                    ->onQueue($this->config->verificationQueue)
                    ->afterCommit();
            }

            return $document;
        });
    }

    private function assertGroupBelongsToOwner(?string $groupId, Model $owner, DocumentType $type): void
    {
        if ($groupId === null) {
            return;
        }

        $known = $this->documentRepository->groupExistsForOwner(
            $owner->getMorphClass(),
            (string) $owner->getKey(),
            $type->id,
            $groupId
        );

        if (! $known) {
            throw ValidationException::withMessages([
                'document_group_id' => 'This document group does not belong to the owner and document type.',
            ]);
        }
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
     *
     * The slot is guarded by a unique index on `latest_marker`, so two uploads racing for an empty slot cannot
     * both win. The loser's insert is rejected, rolled back to its savepoint, and run once more: the second
     * pass finds the winner and becomes its next version. Only a second collision is surfaced.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws SlotConflict
     */
    public function attachStorageFile(
        StorageFile $storageFile,
        DocumentType $type,
        Model $documentable,
        string $clientFilename,
        array $metadata = [],
        ?string $documentGroupId = null,
        DocumentStatus $status = DocumentStatus::Committed
    ): Document {
        $place = fn () => DB::transaction(fn () => $this->placeInSlot($storageFile, $type, $documentable, $clientFilename, $metadata, $documentGroupId, $status));

        try {
            return $place();
        } catch (UniqueConstraintViolationException) {
            try {
                return $place();
            } catch (UniqueConstraintViolationException $e) {
                throw new SlotConflict($type, $documentable, $e);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function placeInSlot(
        StorageFile $storageFile,
        DocumentType $type,
        Model $documentable,
        string $clientFilename,
        array $metadata,
        ?string $documentGroupId,
        DocumentStatus $status
    ): Document {
        $normalizedName = FilenameNormalizer::normalize($clientFilename);

        $metadata = array_merge([
            'size' => $storageFile->size_bytes,
            'mime_type' => $storageFile->mime_type,
        ], $normalizedName === $clientFilename ? [] : ['original_filename' => $clientFilename], $metadata);

        $version = 1;
        $groupId = $documentGroupId;
        $previousLatest = null;

        if ($groupId !== null) {
            $previousLatest = $this->documentRepository->findLatest(
                $documentable->getMorphClass(),
                (string) $documentable->getKey(),
                $type->id,
                $groupId,
                lock: true
            );

            $version = $this->demoteAndResolveVersion($previousLatest, $type);
        } elseif ($type->allows_multiple) {
            $groupId = (string) Str::uuid();
        } else {
            $previousLatest = $this->documentRepository->findLatest(
                $documentable->getMorphClass(),
                (string) $documentable->getKey(),
                $type->id,
                lock: true
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
            'client_filename' => $normalizedName,
            'metadata' => $metadata,
            'version' => $version,
            'status' => $status,
            'is_latest' => true,
            'latest_marker' => $type->allows_multiple ? $groupId : $type->id,
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
    private function demoteAndResolveVersion(?Document $previousLatest, DocumentType $type): int
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
