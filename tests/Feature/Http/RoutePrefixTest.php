<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Support\Facades\Route;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Exceptions\InvalidDocumentableConfig;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * An app that already owns `documents/*` routes moves the package's under another prefix with `route_prefix`.
 */
class RoutePrefixTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('documentable.route_prefix', '/uploads/');
    }

    /**
     * @return list<string>
     */
    private function uris(): array
    {
        return collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();
    }

    public function test_the_routes_are_mounted_under_the_configured_prefix(): void
    {
        $uris = $this->uris();

        $this->assertContains('uploads', $uris);
        $this->assertContains('uploads/{document}/url', $uris);
        $this->assertContains('uploads/multipart/initiate', $uris);
        $this->assertContains('uploads/handshake/answer', $uris);
    }

    public function test_nothing_is_left_under_the_default_prefix(): void
    {
        $this->assertSame([], array_values(array_filter($this->uris(), fn (string $uri) => $uri === 'documents' || str_starts_with($uri, 'documents/'))));
    }

    public function test_the_middleware_and_throttle_still_apply(): void
    {
        $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'uploads' && in_array('POST', $route->methods()));
        $middleware = $route->gatherMiddleware();

        $this->assertContains('api', $middleware);
        $this->assertTrue(collect($middleware)->contains(fn ($m) => str_starts_with($m, 'throttle:')));
    }

    public function test_a_prefix_of_nothing_but_slashes_is_refused(): void
    {
        config()->set('documentable.route_prefix', '//');

        $this->expectException(InvalidDocumentableConfig::class);
        $this->expectExceptionMessage('route_prefix');

        $this->app->forgetInstance(DocumentableConfig::class);
        $this->app->make(DocumentableConfig::class);
    }
}
