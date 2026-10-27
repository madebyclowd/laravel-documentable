<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Integrity;

/**
 * One owner and single-slot document type that has more than one document holding a `latest_marker`.
 *
 * @internal
 */
final readonly class DuplicateLatestSlot
{
    /**
     * @param  non-empty-list<string>  $documentIds  best candidate first: highest version, then newest
     */
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public string $documentTypeId,
        public array $documentIds,
    ) {}

    public function keptDocumentId(): string
    {
        return $this->documentIds[0];
    }

    /**
     * @return list<string>
     */
    public function demotedDocumentIds(): array
    {
        return array_slice($this->documentIds, 1);
    }
}
