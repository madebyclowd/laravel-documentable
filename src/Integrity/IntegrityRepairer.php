<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Integrity;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentPurger;

/**
 * Turns an {@see IntegrityReport} into a reviewable plan and executes it. Planning reads only; executing is
 * the one place that writes, and it logs every change it makes.
 *
 * What it does is deliberately small: it never deletes a document and never deletes a blob another row still
 * uses. A duplicate latest document is demoted, not removed, so its history stays.
 *
 * @internal
 */
final class IntegrityRepairer
{
    public function __construct(private readonly DocumentPurger $purger) {}

    /**
     * @return list<RepairStep>
     */
    public function plan(IntegrityReport $report): array
    {
        $steps = [];

        foreach ($report->duplicateSlots as $slot) {
            $steps[] = RepairStep::keepDocument($slot->keptDocumentId());

            foreach ($slot->demotedDocumentIds() as $documentId) {
                $steps[] = RepairStep::demoteDocument($documentId, $slot->keptDocumentId());
            }
        }

        foreach ($report->duplicateStorageFiles as $duplicate) {
            $steps[] = RepairStep::keepStorageFile($duplicate->keptStorageFileId());

            foreach ($duplicate->extraStorageFileIds() as $storageFileId) {
                $steps[] = RepairStep::repointStorageFile($storageFileId, $duplicate->keptStorageFileId());
                $steps[] = RepairStep::removeStorageFile($storageFileId, $duplicate->keptStorageFileId());
            }
        }

        return $steps;
    }

    /**
     * Run every step that changes data, in order. A step that could not finish is returned so the caller can
     * fail loudly; the steps before it stay done, because each one leaves the data valid on its own.
     *
     * @param  list<RepairStep>  $plan
     * @return list<RepairStep> steps that did not complete
     */
    public function apply(array $plan): array
    {
        $failed = [];

        foreach ($plan as $step) {
            if (! $step->changesData()) {
                continue;
            }

            if ($this->applyStep($step)) {
                Log::info("documents:repair applied: {$step->description}");
            } else {
                $failed[] = $step;
                Log::error("documents:repair could not apply: {$step->description}");
            }
        }

        return $failed;
    }

    private function applyStep(RepairStep $step): bool
    {
        return match ($step->action) {
            RepairAction::DemoteDocument => $this->demote($step->subjectId),
            RepairAction::RepointStorageFile => $this->repoint($step->subjectId, (string) $step->targetId),
            RepairAction::RemoveStorageFile => $this->remove($step->subjectId, (string) $step->targetId),
            RepairAction::Keep => true,
        };
    }

    private function demote(string $documentId): bool
    {
        Document::withTrashed()->whereKey($documentId)->update(['is_latest' => false, 'latest_marker' => null]);

        return true;
    }

    private function repoint(string $fromId, string $toId): bool
    {
        DB::transaction(function () use ($fromId, $toId) {
            Document::withTrashed()->where('storage_file_id', $fromId)->update(['storage_file_id' => $toId]);
            UploadSession::query()->where('storage_file_id', $fromId)->update(['storage_file_id' => $toId]);
        });

        return true;
    }

    /**
     * Two rows can name the very same object, so the blob is only deleted when the kept row points elsewhere.
     * Deleting by path first would destroy the file the kept row still serves.
     */
    private function remove(string $storageFileId, string $keptStorageFileId): bool
    {
        $extra = StorageFile::find($storageFileId);

        if ($extra === null) {
            return true;
        }

        $kept = StorageFile::find($keptStorageFileId);

        if ($kept !== null && $kept->disk === $extra->disk && $kept->path === $extra->path) {
            return (bool) $extra->delete();
        }

        return $this->purger->removeStorageFileIfUnreferenced($extra);
    }
}
