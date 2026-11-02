<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Provider;

use MadeByClowd\Documentable\Exceptions\InsecureConfiguration;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * The whole application, booted as a deploy would: production, the package routes mounted and no authorizer
 * named. The boot itself fails.
 */
class ProductionBootTest extends TestCase
{
    private bool $production = false;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        if ($this->production) {
            $app->detectEnvironment(fn () => 'production');
            $app['config']->set('documentable.authorization.resolver', null);
        }
    }

    protected function defineDatabaseMigrations(): void {}

    public function test_the_application_does_not_boot(): void
    {
        $this->production = true;

        $this->expectException(InsecureConfiguration::class);

        $this->refreshApplication();
    }
}
