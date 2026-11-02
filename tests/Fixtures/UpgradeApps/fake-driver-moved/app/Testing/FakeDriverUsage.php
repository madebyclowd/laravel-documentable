<?php

declare(strict_types=1);

namespace App\Testing;

use Tests\Fixtures\FakeMultipartUploadDriver;

class FakeDriverUsage
{
    public function make(): FakeMultipartUploadDriver
    {
        return new FakeMultipartUploadDriver;
    }
}
