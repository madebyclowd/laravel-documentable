<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Support\Facades\Route;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/feedback.md #1 — shipped routes mounted
 * under a bare 'api' group with no session/auth. See
 * docs/implementations/v2.0.0/phase-11-configurable-route-middleware.md.
 */
class RouteMiddlewareConfigTest extends TestCase
{
    public function test_default_middleware_is_api_plus_throttle(): void
    {
        $route = collect(Route::getRoutes())
            ->first(fn ($route) => $route->uri() === 'documents' && in_array('POST', $route->methods()));
        $middleware = $route->gatherMiddleware();

        $this->assertContains('api', $middleware);
        $this->assertTrue(collect($middleware)->contains(fn ($m) => str_starts_with($m, 'throttle:')));
        $this->assertNotContains('web', $middleware);
        $this->assertNotContains('auth', $middleware);
    }
}
