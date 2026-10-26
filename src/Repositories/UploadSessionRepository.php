<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Repositories;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * Every change to an upload session goes through here, under a row lock, so two requests racing on the same
 * session cannot both win a transition.
 *
 * @internal
 */
final class UploadSessionRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): UploadSession
    {
        return UploadSession::create($attributes);
    }

    /**
     * The caller's own session. An authenticated user reaches only sessions started under their key; a caller
     * with no user reaches only sessions that have none. A session id alone is never enough.
     *
     * @throws ModelNotFoundException
     */
    public function findOwnedOrFail(string $id, ?Authenticatable $user): UploadSession
    {
        $query = UploadSession::whereKey($id);

        $user === null
            ? $query->whereNull('user_id')
            : $query->where('user_id', (string) $user->getAuthIdentifier());

        return $query->firstOrFail();
    }

    /**
     * Open sessions whose expiry has passed, for the reaper.
     *
     * @return Collection<int, UploadSession>
     */
    public function expiredBefore(Carbon $at): Collection
    {
        return UploadSession::whereIn('status', $this->openStatuses())->where('expires_at', '<', $at)->get();
    }

    /**
     * @return list<string>
     */
    private function openStatuses(): array
    {
        return array_values(array_map(
            fn (UploadSessionStatus $status) => $status->value,
            array_filter(UploadSessionStatus::cases(), fn (UploadSessionStatus $status) => $status->isOpen())
        ));
    }

    /**
     * Open sessions created before `$at`, whatever their stored expiry: the reaper's `--hours` override.
     *
     * @return Collection<int, UploadSession>
     */
    public function openCreatedBefore(Carbon $at): Collection
    {
        return UploadSession::whereIn('status', $this->openStatuses())->where('created_at', '<', $at)->get();
    }

    /**
     * @throws IllegalUploadSessionTransition
     */
    public function markUploaded(UploadSession $session, ?StorageFile $storageFile = null): UploadSession
    {
        return $this->transition($session, UploadSessionStatus::Uploaded, [
            'storage_file_id' => $storageFile?->id,
        ]);
    }

    /**
     * Record what verification measured on the stored object, and move the session to `uploaded`.
     *
     * @param  array{hash: string, mime: string, size: int}  $facts
     *
     * @throws IllegalUploadSessionTransition
     */
    public function markVerified(UploadSession $session, array $facts): UploadSession
    {
        return $this->transition($session, UploadSessionStatus::Uploaded, [
            'object_hash' => $facts['hash'],
            'object_mime' => $facts['mime'],
            'object_size' => $facts['size'],
        ]);
    }

    /**
     * @throws IllegalUploadSessionTransition
     */
    public function markAttached(UploadSession $session, Model $owner, ?StorageFile $storageFile = null): UploadSession
    {
        return $this->transition($session, UploadSessionStatus::Attached, array_filter([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => (string) $owner->getKey(),
            'storage_file_id' => $storageFile?->id,
        ], fn ($value) => $value !== null));
    }

    /**
     * @throws IllegalUploadSessionTransition
     */
    public function markExpired(UploadSession $session): UploadSession
    {
        return $this->transition($session, UploadSessionStatus::Expired);
    }

    /**
     * @throws IllegalUploadSessionTransition
     */
    public function markAborted(UploadSession $session): UploadSession
    {
        return $this->transition($session, UploadSessionStatus::Aborted);
    }

    /**
     * Re-reads the row under a lock before deciding, so the check sees the committed status and not whatever
     * the caller's copy remembers.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws IllegalUploadSessionTransition
     */
    private function transition(UploadSession $session, UploadSessionStatus $to, array $attributes = []): UploadSession
    {
        return DB::transaction(function () use ($session, $to, $attributes) {
            $locked = UploadSession::whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $locked->status->transitionTo($to);

            $locked->forceFill($attributes + ['status' => $to])->save();

            return $locked;
        });
    }
}
