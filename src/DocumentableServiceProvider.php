<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Console\Commands\AttachModelCommand;
use MadeByClowd\Documentable\Console\Commands\CleanOrphanedDocumentsCommand;
use MadeByClowd\Documentable\Console\Commands\ConfigureBucketCorsCommand;
use MadeByClowd\Documentable\Console\Commands\ConfigureBucketLifecycleCommand;
use MadeByClowd\Documentable\Console\Commands\InstallCommand;
use MadeByClowd\Documentable\Console\Commands\ListDocumentTypesCommand;
use MadeByClowd\Documentable\Console\Commands\MakeAuthorizerCommand;
use MadeByClowd\Documentable\Console\Commands\RepairIntegrityCommand;
use MadeByClowd\Documentable\Console\Commands\SyncDocumentTypesCommand;
use MadeByClowd\Documentable\Console\Commands\UpgradeCheckCommand;
use MadeByClowd\Documentable\Console\Commands\VerifyDocumentIntegrityCommand;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Defaults\DefaultStoragePathGenerator;
use MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer;
use MadeByClowd\Documentable\Defaults\NullFileScanner;
use MadeByClowd\Documentable\Defaults\TypeAndTenantScope;
use MadeByClowd\Documentable\Exceptions\InsecureConfiguration;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\PresignClientFactory;
use MadeByClowd\Documentable\Services\Presigner;

class DocumentableServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/documentable.php', 'documentable');

        // One presign client per disk for the whole process: building an S3 client reads its credentials and config.
        $this->app->singleton(PresignClientFactory::class);
        $this->app->singleton(Presigner::class);

        $this->app->singleton(DocumentableConfig::class, fn ($app) => DocumentableConfig::fromArray(
            (array) $app['config']->get('documentable', []),
            array_map('strval', array_keys((array) $app['config']->get('filesystems.disks', []))),
        ));

        $this->app->bind(
            AuthorizesDocumentAccess::class,
            fn ($app) => $app->make(config('documentable.authorization.resolver') ?? GateDocumentAuthorizer::class)
        );

        $this->app->bind(
            ScansUploadedFile::class,
            fn ($app) => $app->make(config('documentable.security.scanner') ?? NullFileScanner::class)
        );

        $this->app->bind(
            ResolvesDedupScope::class,
            fn ($app) => $app->make(config('documentable.dedup.scope_resolver') ?? TypeAndTenantScope::class)
        );

        $this->app->bind(
            GeneratesStoragePath::class,
            fn ($app) => $app->make(config('documentable.storage_path.generator') ?? DefaultStoragePathGenerator::class)
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Resolve once so an invalid value fails the boot with the key and the fix, not the first upload.
        $config = $this->app->make(DocumentableConfig::class);

        if (config('documentable.load_migrations', true) || $this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($config->loadRoutes) {
            $this->failWithoutAuthorizerInProduction($config);
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        $this->registerAboutSection();

        $this->app->booted(function () use ($config) {
            if (! RateLimiter::limiter($config->throttle)) {
                RateLimiter::for($config->throttle, fn (Request $request) => Limit::perMinute(60)->by(
                    (string) ($request->user()?->getAuthIdentifier() ?? $request->ip())
                ));
            }

            if (! RateLimiter::limiter($config->dedupThrottle)) {
                RateLimiter::for($config->dedupThrottle, fn (Request $request) => Limit::perMinute(60)->by(
                    (string) ($request->user()?->getAuthIdentifier() ?? $request->ip())
                ));
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/documentable.php' => config_path('documentable.php'),
            ], 'documentable-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'documentable-migrations');

            $this->publishes([
                __DIR__.'/../routes/api.php' => base_path('routes/documentable.php'),
            ], 'documentable-routes');

            $this->commands([
                AttachModelCommand::class,
                CleanOrphanedDocumentsCommand::class,
                ConfigureBucketCorsCommand::class,
                ConfigureBucketLifecycleCommand::class,
                InstallCommand::class,
                MakeAuthorizerCommand::class,
                RepairIntegrityCommand::class,
                SyncDocumentTypesCommand::class,
                ListDocumentTypesCommand::class,
                UpgradeCheckCommand::class,
                VerifyDocumentIntegrityCommand::class,
            ]);

            $this->app->booted(function () {
                $schedule = $this->app->make(Schedule::class);
                $frequency = config('documentable.lifecycle.reaper_frequency', 'hourly');

                $schedule->command('documents:clean-orphaned')->{$frequency}();
            });
        }
    }

    /**
     * Without a configured authorizer the Gate-backed default denies everything the app has not defined, which
     * is safe but is also what an app that forgot the key looks like. Production must name its authorizer, so a
     * deploy that skipped it fails at boot instead of serving a surface nobody chose.
     */
    protected function failWithoutAuthorizerInProduction(DocumentableConfig $config): void
    {
        if ($this->app->isProduction() && $config->authorizerResolver === null) {
            throw InsecureConfiguration::noAuthorizer();
        }
    }

    protected function registerAboutSection(): void
    {
        AboutCommand::add('Documentable', fn () => [
            'Authorizer' => config('documentable.authorization.resolver') ?? GateDocumentAuthorizer::class,
            'Routes' => config('documentable.load_routes', true) ? 'mounted' : 'not mounted',
            'Middleware' => implode(', ', (array) config('documentable.middleware', ['api'])),
            'Throttle' => (string) config('documentable.throttle', 'documents'),
            'Disk' => $this->app->make(DocumentableConfig::class)->disk,
            'Etag strategy' => $this->app->make(DocumentableConfig::class)->etagStrategy,
            'Reaper' => $this->app->make(DocumentableConfig::class)->reaperFrequency,
            'Document types' => $this->documentTypeSummary(),
        ]);
    }

    /**
     * How the code-first catalog in config compares with the table, so a deploy that forgot
     * `documents:sync-types` shows up in `php artisan about`.
     */
    protected function documentTypeSummary(): string
    {
        $configured = array_keys((array) config('documentable.types', []));

        try {
            $active = DocumentType::pluck('code')->all();
        } catch (\Throwable) {
            return count($configured).' in config, database not reachable';
        }

        $notSynced = count(array_diff($configured, $active));

        return count($configured).' in config, '.count($active).' active in database, '.$notSynced.' in config not yet synced';
    }
}
