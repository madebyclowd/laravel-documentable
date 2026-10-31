<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Provider;

use Illuminate\Support\Facades\Artisan;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\DocumentableServiceProvider;
use MadeByClowd\Documentable\Exceptions\InvalidDocumentableConfig;
use MadeByClowd\Documentable\Tests\TestCase;

class ConfigValidationTest extends TestCase
{
    public function test_the_resolved_config_is_shared_and_typed(): void
    {
        $config = $this->app->make(DocumentableConfig::class);

        $this->assertSame($config, $this->app->make(DocumentableConfig::class));
        $this->assertSame('documents', $config->throttle);
    }

    public function test_about_reports_the_resolved_summary(): void
    {
        Artisan::call('about', ['--json' => true]);
        $about = json_decode(Artisan::output(), true)['documentable'];

        $this->assertSame('s3', $about['disk']);
        $this->assertSame('server-authoritative', $about['etag_strategy']);
        $this->assertSame('hourly', $about['reaper']);
    }

    public function test_booting_with_an_invalid_value_throws_with_the_key_and_the_fix(): void
    {
        $this->app['config']->set('documentable.lifecycle.reaper_frequency', 'whenever');
        $this->app->forgetInstance(DocumentableConfig::class);

        $this->expectException(InvalidDocumentableConfig::class);
        $this->expectExceptionMessage('documentable.lifecycle.reaper_frequency');

        (new DocumentableServiceProvider($this->app))->boot();
    }

    public function test_booting_with_invalid_retention_hold_throws(): void
    {
        $this->app['config']->set('documentable.retention.hold', 'not-an-array');
        $this->app->forgetInstance(DocumentableConfig::class);

        $this->expectException(InvalidDocumentableConfig::class);
        $this->expectExceptionMessage('documentable.retention.hold');

        (new DocumentableServiceProvider($this->app))->boot();
    }
}
