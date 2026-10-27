<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Integrity;

/**
 * Several `storage_files` rows sharing one `(file_hash, disk)` pair, which the unique dedup index forbids.
 *
 * @internal
 */
final readonly class DuplicateStorageFile
{
    /**
     * @param  non-empty-list<string>  $storageFileIds  oldest first; the first one is kept
     */
    public function __construct(
        public string $fileHash,
        public string $disk,
        public array $storageFileIds,
    ) {}

    public function keptStorageFileId(): string
    {
        return $this->storageFileIds[0];
    }

    /**
     * @return list<string>
     */
    public function extraStorageFileIds(): array
    {
        return array_slice($this->storageFileIds, 1);
    }
}
