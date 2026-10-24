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
     *
     * `path` and `upload_id` are unindexed `text` columns (a provider's upload id and a storage key outgrow any
     * index MySQL can build), so the index on `user_id` narrows the scan to that user's open sessions. The
     * strict comparison afterwards keeps the match case-sensitive on engines whose default collation is not.
     */
    public function findOwned(string $path, string $uploadId, string $userId): ?MultipartUpload
    {
        return MultipartUpload::where('user_id', $userId)
            ->where('path', $path)
            ->where('upload_id', $uploadId)
            ->get()
            ->first(fn (MultipartUpload $session) => $session->path === $path && $session->upload_id === $uploadId);
    }

    public function delete(MultipartUpload $upload): bool
    {
        return (bool) $upload->delete();
    }
}
