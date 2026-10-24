<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Exceptions;

use RuntimeException;

/**
 * Raised by a `MultipartUploadDriver` when the provider has no such upload any more (S3's `NoSuchUpload`): it
 * was already aborted, completed, or removed by a bucket lifecycle rule. Aborting something that is already gone
 * has reached its goal, so `DocumentService` treats this as a successful abort rather than a failure.
 */
class MultipartUploadNotFoundException extends RuntimeException
{
    public static function forUpload(string $path, string $uploadId, ?\Throwable $previous = null): self
    {
        return new self("The provider has no multipart upload [{$uploadId}] for [{$path}].", 0, $previous);
    }
}
