<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Provider;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer;
use MadeByClowd\Documentable\DocumentableServiceProvider;
use MadeByClowd\Documentable\Exceptions\InsecureConfiguration;
use MadeByClowd\Documentable\Tests\Fixtures\AllowAllAuthorizer;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Nothing is allowed until the app says so: the default authorizer denies, production refuses to boot its routes
 * without a named authorizer, and the routes sit behind a finite rate limit.
 */
class SecureDefaultsTest extends TestCase
{
    /**
     * These tests need no database, and `migrate` refuses to run unattended in a production environment.
     */
    protected function defineDatabaseMigrations(): void {}

    /**
     * Boots a fresh provider the way the framework would, after the test changed the environment or the config.
     *
     * @param  array<string, mixed>  $config
     */
    private function bootProvider(string $environment, array $config = []): void
    {
        $this->app->detectEnvironment(fn () => $environment);

        foreach ($config as $key => $value) {
            config()->set($key, $value);
        }

        $this->app->forgetInstance(DocumentableConfig::class);

        (new DocumentableServiceProvider($this->app))->boot();
    }

    public function test_production_with_routes_and_no_authorizer_refuses_to_boot(): void
    {
        $this->expectException(InsecureConfiguration::class);
        $this->expectExceptionMessage('documentable.authorization.resolver');

        $this->bootProvider('production', ['documentable.authorization.resolver' => null, 'documentable.load_routes' => true]);
    }

    public function test_the_message_names_the_command_that_scaffolds_an_authorizer(): void
    {
        try {
            $this->bootProvider('production', ['documentable.authorization.resolver' => null]);
            $this->fail('Boot should have thrown.');
        } catch (InsecureConfiguration $e) {
            $this->assertStringContainsString('documentable.authorization.resolver', $e->getMessage());
            $this->assertStringContainsString('php artisan documents:make-authorizer', $e->getMessage());
            $this->assertStringContainsString('documentable.load_routes', $e->getMessage());
        }
    }

    public function test_production_with_a_named_authorizer_boots(): void
    {
        $this->bootProvider('production', ['documentable.authorization.resolver' => AllowAllAuthorizer::class]);
        $this->bootProvider('production', ['documentable.authorization.resolver' => GateDocumentAuthorizer::class]);

        $this->assertTrue(true);
    }

    public function test_production_without_routes_boots_without_an_authorizer(): void
    {
        $this->bootProvider('production', ['documentable.authorization.resolver' => null, 'documentable.load_routes' => false]);

        $this->assertTrue(true);
    }

    public function test_local_and_testing_boot_without_an_authorizer(): void
    {
        $this->bootProvider('local', ['documentable.authorization.resolver' => null]);
        $this->bootProvider('testing', ['documentable.authorization.resolver' => null]);

        $this->assertTrue(true);
    }

    public function test_about_names_the_gate_backed_default_when_nothing_is_configured(): void
    {
        config()->set('documentable.authorization.resolver', null);

        Artisan::call('about', ['--json' => true]);
        $about = json_decode(Artisan::output(), true);

        $this->assertSame(GateDocumentAuthorizer::class, $about['documentable']['authorizer']);
        $this->assertArrayNotHasKey('guest_user_id', $about['documentable']);
    }

    public function test_the_default_limiter_is_sixty_a_minute_per_user_or_ip(): void
    {
        config()->set('documentable.throttle', 'documents-default');
        $this->bootProvider('testing');

        $limiter = RateLimiter::limiter('documents-default');
        $this->assertNotNull($limiter);

        $guest = $limiter(Request::create('/documents', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));
        $this->assertInstanceOf(Limit::class, $guest);
        $this->assertSame(60, $guest->maxAttempts);
        $this->assertSame(1, $guest->decaySeconds / 60);
        $this->assertSame('203.0.113.9', $guest->key);

        $request = Request::create('/documents', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->setUserResolver(fn () => (new User)->forceFill(['id' => 7]));
        $this->assertSame('7', $limiter($request)->key);
    }

    public function test_a_limiter_the_app_defined_is_left_alone(): void
    {
        RateLimiter::for('documents-custom', fn () => Limit::perMinute(5));
        config()->set('documentable.throttle', 'documents-custom');

        $this->bootProvider('testing');

        $this->assertSame(5, RateLimiter::limiter('documents-custom')(Request::create('/'))->maxAttempts);
    }

    public function test_the_package_routes_are_mounted_behind_the_throttle(): void
    {
        $route = Route::getRoutes()->match(Request::create('/documents/types', 'GET'));

        $this->assertContains('throttle:documents', $route->gatherMiddleware());
    }
}
