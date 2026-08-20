<?php

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * config('documentable.middleware') is read once, at route-registration time
 * (DocumentableServiceProvider::boot() -> loadRoutesFrom()), so it must be set
 * before the app boots — via getEnvironmentSetUp(), not config()->set() inside a
 * test method. Kept in its own class/file (not a second method on
 * RouteMiddlewareConfigTest) for exactly that reason.
 */
class RouteMiddlewareConfigMonolithTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('documentable.middleware', ['web', 'auth']);
    }

    public function test_configured_middleware_includes_web_and_auth(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($route) => $route->uri() === 'documents' && in_array('POST', $route->methods()));
        $middleware = $route->gatherMiddleware();

        $this->assertContains('web', $middleware);
        $this->assertContains('auth', $middleware);
        $this->assertTrue(collect($middleware)->contains(fn ($m) => str_starts_with($m, 'throttle:')));
    }
}
