<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Integrity;

/**
 * What the integrity inspector found. The first three kinds block the schema constraints; the last two are
 * worth knowing before an upgrade but need no action.
 *
 * @internal
 */
final readonly class IntegrityReport
{
    /**
     * @param  list<DuplicateLatestSlot>  $duplicateSlots
     * @param  list<DuplicateStorageFile>  $duplicateStorageFiles
     * @param  list<string>  $documentsMissingStorageFile  document ids whose `storage_file_id` matches no row
     * @param  list<string>  $documentsOutsideKeyType  document ids whose owner key does not fit `key_type`
     */
    public function __construct(
        public array $duplicateSlots = [],
        public array $duplicateStorageFiles = [],
        public array $documentsMissingStorageFile = [],
        public array $documentsOutsideKeyType = [],
        public string $keyType = 'string',
        public int $openUploadSessions = 0,
        public int $ownerlessDocuments = 0,
    ) {}

    /**
     * Problems a constraint would reject.
     */
    public function hasBlockingFindings(): bool
    {
        return $this->hasRepairableFindings() || $this->documentsMissingStorageFile !== [] || $this->documentsOutsideKeyType !== [];
    }

    /**
     * The subset `documents:repair` can fix without a human decision.
     */
    public function hasRepairableFindings(): bool
    {
        return $this->duplicateSlots !== [] || $this->duplicateStorageFiles !== [];
    }
}
