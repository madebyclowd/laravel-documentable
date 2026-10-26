<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Repositories;

use MadeByClowd\Documentable\Models\StorageFile;

class StorageFileRepository
{
    public function findByHash(string $hash): ?StorageFile
    {
        return StorageFile::where('file_hash', $hash)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): StorageFile
    {
        return StorageFile::create($data);
    }

    public function delete(StorageFile $storageFile): bool
    {
        return (bool) $storageFile->delete();
    }
}
