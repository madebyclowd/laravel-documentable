<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Enums;

use MadeByClowd\Documentable\Exceptions\IllegalUploadSessionTransition;

/**
 * Where an upload session is in its life. The allowed moves live here, so no code path can skip a step:
 *
 * initiated -> uploaded -> attached, and an open session (initiated or uploaded) may also expire or be aborted.
 * Attached, expired and aborted are final.
 *
 * @api
 */
enum UploadSessionStatus: string
{
    case Initiated = 'initiated';
    case Uploaded = 'uploaded';
    case Attached = 'attached';
    case Expired = 'expired';
    case Aborted = 'aborted';

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Initiated => [self::Uploaded, self::Expired, self::Aborted],
            self::Uploaded => [self::Attached, self::Expired, self::Aborted],
            self::Attached, self::Expired, self::Aborted => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * @throws IllegalUploadSessionTransition
     */
    public function transitionTo(self $next): self
    {
        if (! $this->canTransitionTo($next)) {
            throw new IllegalUploadSessionTransition($this, $next);
        }

        return $next;
    }

    /**
     * A session the reaper may still expire: it holds a path and possibly a provider upload.
     */
    public function isOpen(): bool
    {
        return $this === self::Initiated || $this === self::Uploaded;
    }

    public function isTerminal(): bool
    {
        return ! $this->isOpen();
    }
}
