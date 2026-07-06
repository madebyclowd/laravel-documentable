<?php

namespace MadeByClowd\Documentable\Exceptions;

use RuntimeException;
use Throwable;

class DiskDoesNotSupportTemporaryUrlsException extends RuntimeException
{
    public static function forDisk(string $disk, Throwable $previous): self
    {
        return new self(
            "Disk [{$disk}] does not support temporary URLs, which GET /documents/{id}/url requires ".
            'for every document regardless of size. Switch to an S3-compatible disk, or register '.
            "Storage::disk('{$disk}')->buildTemporaryUrlsUsing(...) yourself. See config('documentable.disk')'s comment.",
            previous: $previous
        );
    }
}
