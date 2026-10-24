<?php

namespace MadeByClowd\Documentable\Repositories;

use MadeByClowd\Documentable\Models\StorageFile;

class StorageFileRepository
{
    public function findByHash(string $hash): ?StorageFile
    {
        return StorageFile::where('file_hash', $hash)->first();
    }

    public function existsAtPath(string $disk, string $path): bool
    {
        return StorageFile::where('disk', $disk)->where('path', $path)->exists();
    }

    public function create(array $data): StorageFile
    {
        return StorageFile::create($data);
    }

    public function delete(StorageFile $storageFile): bool
    {
        return (bool) $storageFile->delete();
    }
}
