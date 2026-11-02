<?php

declare(strict_types=1);

namespace App\Services;

class CleanService
{
    // Clean service with no v2 calls
    public function doWork(): string
    {
        return 'clean';
    }
}
