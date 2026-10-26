<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Auth;

/**
 * Who is acting right now, for the audit columns and the access log. Best-effort: null when nobody is
 * authenticated (CLI, queued jobs), and never a hard requirement to upload or delete.
 *
 * @internal
 */
final class ActorResolver
{
    public function __construct(private readonly Config $config) {}

    /**
     * The authenticated user's id, regardless of whether audit columns are enabled.
     */
    public function id(): ?string
    {
        $id = Auth::id();

        return $id !== null ? (string) $id : null;
    }

    /**
     * The id to store in `created_by` and `deleted_by`: null unless `documentable.audit.enabled` is on.
     */
    public function idForAudit(): ?string
    {
        if (! $this->config->get('documentable.audit.enabled', false)) {
            return null;
        }

        return $this->id();
    }
}
