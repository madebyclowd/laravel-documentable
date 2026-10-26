<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Events\DocumentReassociated;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Events\DocumentVersionSuperseded;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Repositories\DocumentRepository;

/**
 * Puts a stored file into an owner's slot: resolves the document group, demotes or replaces the previous
 * latest, bumps the version, and announces it. Every transport (upload, multipart, direct PUT, reassociation)
 * goes through here so a slot can never end up with two latest documents.
 *
 * @internal
 */
final class DocumentAttacher
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly ActorResolver $actor,
    ) {}

    /**
     * Move a document to a different owner, typically a detached upload that finally has its real owner.
     *
     * The move goes through the same slot and version resolution as an upload. For a single-slot type whose new
     * owner already holds a latest document, the moved document joins that slot's chain: it takes the group, the
     * previous latest is demoted (or soft-deleted when the type does not keep history) and the version is
     * incremented, so the owner never ends up with two "latest" documents. A multi-slot type keeps the moved
     * document in its own slot, and moving a document to the owner it already has changes nothing.
     */
    public function reassociate(Document $document, Model $newOwner): Document
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
     * @param  array<string, mixed>  $metadata
     */
    public function attachStorageFile(
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
