<?php

namespace MadeByClowd\Documentable\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\DocumentableServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stand-in "documentable" owner model used across feature tests —
        // not part of the package, just a fixture to attach documents to.
        Schema::create('test_models', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Get package providers.
     *
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            DocumentableServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('documentable.load_migrations', true);
    }
}
