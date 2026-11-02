<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * A policy an app registers for UploadSession: only the user a session was started for may finalize it, and
 * user 1 may attach any session.
 */
class UploadSessionPolicy
{
    public function finalize(Authenticatable $user, UploadSession $session): bool
    {
        return (string) $user->getAuthIdentifier() === $session->user_id;
    }

    public function attach(Authenticatable $user, UploadSession $session, Model $owner): bool
    {
        return $user->getAuthIdentifier() === 1;
    }
}
