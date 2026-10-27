<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Repositories;

use MadeByClowd\Documentable\Models\StorageFile;

class StorageFileRepository
{
    /**
     * The stored file for a dedup key on one disk. A blob only exists on the disk it was written to, so the
     * disk is part of the identity. With `$lock` the row is read `FOR UPDATE`, which makes a concurrent purge
     * wait until the caller's document references it; it only holds inside a transaction.
     */
    public function findByHash(string $hash, string $disk, bool $lock = false): ?StorageFile
    {
        $query = StorageFile::where('file_hash', $hash)->where('disk', $disk);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Create the stored file for `(file_hash, disk)`, or return the one a concurrent upload created first. The
     * unique index decides the winner, so the caller compares the returned `path` with the one it tried to
     * register to learn whether it lost.
     *
     * @param  array<string, mixed>  $attributes  everything but `file_hash` and `disk`
     */
    public function createOrFirst(string $hash, string $disk, array $attributes): StorageFile
    {
        return StorageFile::createOrFirst(['file_hash' => $hash, 'disk' => $disk], $attributes);
    }

    public function delete(StorageFile $storageFile): bool
    {
        return (bool) $storageFile->delete();
    }
}
