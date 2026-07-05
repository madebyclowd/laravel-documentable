<?php

namespace MadeByClowd\Documentable\Exceptions;

use RuntimeException;

class UnsupportedMultipartDriverException extends RuntimeException
{
    public static function forDisk(string $disk, string $flysystemDriver): self
    {
        return new self("Multipart upload not supported for disk [{$disk}] (Flysystem driver [{$flysystemDriver}] has no registered MultipartUploadDriver in config('documentable.multipart.drivers')).");
    }
}
