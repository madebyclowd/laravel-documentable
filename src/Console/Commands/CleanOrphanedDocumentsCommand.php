<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use MadeByClowd\Documentable\Models\DedupChallenge;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Repositories\UploadSessionRepository;
use MadeByClowd\Documentable\Services\DocumentService;
use Throwable;

/**
 * Sweeps what abandoned uploads leave behind: upload sessions past their expiry, and with `--storage-files`
 * the stored files no document references. Expiring a session releases what it holds (it aborts a multipart
 * upload on the provider, so an incomplete S3 upload stops accruing cost, and deletes an unattached object),
 * through the same code path as an owner's abort.
 */
class CleanOrphanedDocumentsCommand extends Command
{
    protected $signature = 'documents:clean-orphaned
        {--hours= : Override configured TTL — sweep any open session older than N hours regardless of stored expires_at}
        {--storage-files : Also retry deleting stored files that no document references (a failed purge leaves one behind)}';

    protected $description = 'Expire stale upload sessions and release the objects and provider uploads they hold.';

    public function handle(DocumentService $service, UploadSessionRepository $sessions): int
    {
        $hoursOverride = $this->option('hours');

        $stale = $hoursOverride !== null
            ? $sessions->openCreatedBefore(now()->subHours((int) $hoursOverride))
            : $sessions->expiredBefore(now());

        $expired = 0;
        $failed = 0;
        foreach ($stale as $session) {
            try {
                $service->expireUploadSession($session);
                $expired++;
            } catch (Throwable $e) {
                // One unreachable or misbehaving session must not stop the rest of the sweep. Its row stays
                // open, so the next run retries it.
                $failed++;
                Log::error("documents:clean-orphaned could not expire upload session [{$session->id}]: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$expired} stale upload session(s).");

        if ($failed > 0) {
            $this->error("{$failed} upload session(s) could not be expired and stay for the next run.");
        }

        if ($this->option('storage-files')) {
            $this->sweepStorageFiles($service, $hoursOverride);
        }

        $prunedChallenges = DedupChallenge::where('expires_at', '<=', now())
            ->orWhereNotNull('consumed_at')
            ->orWhere('attempts', '>=', 3)
            ->delete();

        if ($prunedChallenges > 0) {
            $this->info("Pruned {$prunedChallenges} expired or consumed dedup challenge(s).");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
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
