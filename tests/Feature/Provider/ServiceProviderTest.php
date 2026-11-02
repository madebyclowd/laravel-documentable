<?php

namespace MadeByClowd\Documentable\Tests\Feature\Provider;

use Illuminate\Support\ServiceProvider;
use MadeByClowd\Documentable\DocumentableServiceProvider;
use MadeByClowd\Documentable\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertIsArray(config('documentable'));
        $this->assertSame('s3', config('documentable.disk'));
        $this->assertSame('server-authoritative', config('documentable.multipart.etag_strategy'));
    }

    public function test_client_helper_is_publishable(): void
    {
        $publishes = ServiceProvider::pathsToPublish(DocumentableServiceProvider::class, 'documentable-client');

        $this->assertNotEmpty($publishes);
        $source = array_key_first($publishes);
        $this->assertNotNull($source);
        $this->assertSame(realpath(__DIR__.'/../../../resources/client/documentable.ts'), realpath($source));
        $this->assertSame(
            resource_path('js/vendor/documentable/documentable.ts'),
            $publishes[$source]
        );
    }
}
