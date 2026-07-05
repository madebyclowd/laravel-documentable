<?php

namespace MadeByClowd\Documentable\Repositories;

use MadeByClowd\Documentable\Models\MultipartUpload;

class MultipartUploadRepository
{
    public function create(array $data): MultipartUpload
    {
        return MultipartUpload::create($data);
    }

    /**
     * Find an upload session, scoped to the user who initiated it. This
     * ownership check is what gates part-url/complete/abort — a session id
     * alone must never be enough.
     */
    public function findOwned(string $path, string $uploadId, string $userId): ?MultipartUpload
    {
        return MultipartUpload::where('path', $path)
            ->where('upload_id', $uploadId)
            ->where('user_id', $userId)
            ->first();
    }

    public function delete(MultipartUpload $upload): bool
    {
        return (bool) $upload->delete();
    }
}
