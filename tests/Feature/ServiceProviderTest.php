<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use MadeByClowd\Documentable\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertIsArray(config('documentable'));
        $this->assertSame('s3', config('documentable.disk'));
        $this->assertSame('server-authoritative', config('documentable.multipart.etag_strategy'));
    }
}
