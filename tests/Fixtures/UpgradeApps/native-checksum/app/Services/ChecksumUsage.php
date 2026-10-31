<?php

declare(strict_types=1);

namespace App\Services;

class ChecksumUsage
{
    public function check($driver, string $disk, string $path): void
    {
        $checksum = $driver->retrieveChecksum($disk, $path);
    }
}
