<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Resolves which document versions are eligible for retention pruning based on
 * the type's versions_kept and versions_retain_days policies, excluding the latest version
 * and any version or group in retention.hold.
 *
 * @internal
 */
final class VersionRetention
{
    /**
     * @return Collection<int, Document>
     */
    public function forGroup(string $groupId, ?CarbonInterface $now = null): Collection
    {
        if (! Str::isUuid($groupId)) {
            return new Collection;
        }

        /** @var list<string> $hold */
        $hold = (array) config('documentable.retention.hold', []);

        if (in_array($groupId, $hold, true)) {
            return new Collection;
        }

        $now ??= Carbon::now();

        $documents = Document::where('document_group_id', $groupId)
            ->orderByDesc('version')
            ->get();

        if ($documents->isEmpty()) {
            return new Collection;
        }

        $type = $documents->first()->documentTypeOrFail();

        if ($type->versions_kept === null && $type->versions_retain_days === null) {
            return new Collection;
        }

        $toPrune = new Collection;
        $position = 0;

        foreach ($documents as $document) {
            $position++;

            if ($document->is_latest) {
                continue;
            }

            if (in_array($document->id, $hold, true) || in_array($document->document_group_id, $hold, true)) {
                continue;
            }

            $exceedsCount = $type->versions_kept !== null
                ? ($position > $type->versions_kept)
                : true;

            $exceedsAge = $type->versions_retain_days !== null
                ? ($document->created_at !== null && $document->created_at->lte($now->copy()->subDays($type->versions_retain_days)))
                : true;

            if ($exceedsCount && $exceedsAge) {
                $toPrune->push($document);
            }
        }

        return $toPrune;
    }

    /**
     * @return Collection<int, Document>
     */
    public function forType(DocumentType $type, ?CarbonInterface $now = null): Collection
    {
        return $this->forGroups(Document::where('document_type_id', $type->id), $now);
    }

    /**
     * @return Collection<int, Document>
     */
    public function all(?CarbonInterface $now = null): Collection
    {
        return $this->forGroups(Document::query(), $now);
    }

    /**
     * @param  Builder<Document>  $documents
     * @return Collection<int, Document>
     */
    private function forGroups(Builder $documents, ?CarbonInterface $now): Collection
    {
        $groupIds = $documents->whereNotNull('document_group_id')->distinct()->pluck('document_group_id');

        $pruned = new Collection;

        foreach ($groupIds as $groupId) {
            $pruned = $pruned->concat($this->forGroup((string) $groupId, $now));
        }

        return $pruned;
    }
}
