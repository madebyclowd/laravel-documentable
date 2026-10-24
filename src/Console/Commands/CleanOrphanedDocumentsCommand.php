<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\DocumentService;

/**
 * Replaces the reference app's CleanOrphanedPendingDocumentsCommand, which
 * only ever swept one app's hardcoded metadata flag and never touched
 * multipart_uploads at all (bugs.md #5). This sweeps both: pending Document
 * rows past expiry (via DocumentService::purge() — dedup-safe, won't delete
 * a physical file another Document still references) and stale
 * MultipartUpload sessions past expiry (via abortMultipartUploadSession() —
 * aborts on the provider first, not just a DB delete, so an incomplete S3
 * upload doesn't keep existing/billing).
 */
class CleanOrphanedDocumentsCommand extends Command
{
    protected $signature = 'documents:clean-orphaned
        {--hours= : Override configured TTL — sweep anything older than N hours regardless of stored expires_at}
        {--storage-files : Also retry deleting stored files that no document references (a failed purge leaves one behind)}';

    protected $description = 'Purge expired pending documents and abort/remove stale multipart upload sessions.';

    public function handle(DocumentService $service): int
    {
        $hoursOverride = $this->option('hours');

        $pendingDocuments = Document::query()->pending();
        if ($hoursOverride !== null) {
            $pendingDocuments->where('created_at', '<', now()->subHours((int) $hoursOverride));
        } else {
            $pendingDocuments->where('expires_at', '<', now());
        }

        $purged = 0;
        foreach ($pendingDocuments->get() as $document) {
            $service->purge($document);
            $purged++;
        }

        $staleSessions = MultipartUpload::query();
        if ($hoursOverride !== null) {
            $staleSessions->where('created_at', '<', now()->subHours((int) $hoursOverride));
        } else {
            $staleSessions->where('expires_at', '<', now());
        }

        $aborted = 0;
        foreach ($staleSessions->get() as $session) {
            $service->abortMultipartUploadSession($session);
            $aborted++;
        }

        $this->info("Purged {$purged} expired pending document(s), aborted {$aborted} stale multipart session(s).");

        if ($this->option('storage-files')) {
            $this->sweepStorageFiles($service, $hoursOverride);
        }

        return self::SUCCESS;
    }

    /**
     * Stored files with no document, trashed ones included, older than the grace period. The grace period keeps
     * the sweep away from a file whose document is still being created in the same upload.
     */
    protected function sweepStorageFiles(DocumentService $service, mixed $hoursOverride): void
    {
        $grace = $hoursOverride !== null
            ? (int) $hoursOverride
            : (int) config('documentable.lifecycle.storage_file_grace_hours', 24);

        $removed = 0;
        $failed = 0;

        StorageFile::query()
            ->where('created_at', '<=', now()->subHours($grace))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('documents')->whereColumn('documents.storage_file_id', 'storage_files.id'))
            ->each(function (StorageFile $storageFile) use ($service, &$removed, &$failed) {
                $service->removeStorageFileIfUnreferenced($storageFile) ? $removed++ : $failed++;
            });

        $this->info("Removed {$removed} unreferenced stored file(s), {$failed} could not be deleted and stay for the next sweep.");
    }
}
