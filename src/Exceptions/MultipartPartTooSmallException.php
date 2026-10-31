<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised by a `MultipartUploadDriver` when complete is called but a non-last part is smaller than the provider's minimum allowed size.
 *
 * @api
 */
class MultipartPartTooSmallException extends RuntimeException
{
    public static function forUpload(string $path, string $uploadId, ?Throwable $previous = null): self
    {
        return new self("Cannot complete multipart upload [{$uploadId}] for [{$path}]: one or more parts are smaller than the required minimum size.", 0, $previous);
    }
}
