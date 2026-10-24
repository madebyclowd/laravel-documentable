<?php

namespace MadeByClowd\Documentable;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use MadeByClowd\Documentable\Console\Commands\AttachModelCommand;
use MadeByClowd\Documentable\Console\Commands\CleanOrphanedDocumentsCommand;
use MadeByClowd\Documentable\Console\Commands\ConfigureBucketCorsCommand;
use MadeByClowd\Documentable\Console\Commands\ConfigureBucketLifecycleCommand;
use MadeByClowd\Documentable\Console\Commands\InstallCommand;
use MadeByClowd\Documentable\Console\Commands\ListDocumentTypesCommand;
use MadeByClowd\Documentable\Console\Commands\MakeAuthorizerCommand;
use MadeByClowd\Documentable\Console\Commands\SyncDocumentTypesCommand;
use MadeByClowd\Documentable\Console\Commands\VerifyDocumentIntegrityCommand;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Defaults\DefaultStoragePathGenerator;
use MadeByClowd\Documentable\Defaults\HashOnlyDedupScope;
use MadeByClowd\Documentable\Defaults\NullFileScanner;
use MadeByClowd\Documentable\Defaults\PermissiveDocumentAuthorizer;
use MadeByClowd\Documentable\Models\DocumentType;

class DocumentableServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/documentable.php', 'documentable');

        $this->app->bind(
            AuthorizesDocumentAccess::class,
            fn ($app) => $app->make(config('documentable.authorization.resolver') ?? PermissiveDocumentAuthorizer::class)
        );

        $this->app->bind(
            ScansUploadedFile::class,
            fn ($app) => $app->make(config('documentable.security.scanner') ?? NullFileScanner::class)
        );

        $this->app->bind(
            ResolvesDedupScope::class,
            fn ($app) => $app->make(config('documentable.dedup.scope_resolver') ?? HashOnlyDedupScope::class)
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
        if (config('documentable.load_migrations', true) || $this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if (config('documentable.load_routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        $this->registerAboutSection();

        $this->app->booted(function () {
            $this->warnAboutInsecureProductionSetup();

            $limiterName = config('documentable.throttle', 'documents');

            if (! RateLimiter::limiter($limiterName)) {
                RateLimiter::for($limiterName, fn ($request) => Limit::none());
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
                SyncDocumentTypesCommand::class,
                ListDocumentTypesCommand::class,
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
     * Production with the package routes mounted and the allow-everything authorizer means any caller can
     * upload, view and delete. A patch release must not fail boot over it, so it is a warning and an
     * `about` row, and the secure default (deny-all, boot failure) is left to the next major.
     */
    protected function isInsecureProductionSetup(): bool
    {
        if (! $this->app->isProduction() || ! config('documentable.load_routes', true)) {
            return false;
        }

        $resolver = config('documentable.authorization.resolver') ?? PermissiveDocumentAuthorizer::class;

        return is_a($resolver, PermissiveDocumentAuthorizer::class, true);
    }

    protected function warnAboutInsecureProductionSetup(): void
    {
        if (! $this->isInsecureProductionSetup()) {
            return;
        }

        Log::warning('Documentable is mounted with the permissive authorizer in production, so every caller can upload, view and delete documents. Set documentable.authorization.resolver to your own AuthorizesDocumentAccess implementation (php artisan make:document-authorizer).');
    }

    protected function registerAboutSection(): void
    {
        AboutCommand::add('Documentable', fn () => [
            'Authorizer' => config('documentable.authorization.resolver') ?? PermissiveDocumentAuthorizer::class,
            'Routes' => config('documentable.load_routes', true) ? 'mounted' : 'not mounted',
            'Middleware' => implode(', ', (array) config('documentable.middleware', ['api'])),
            'Throttle' => (string) config('documentable.throttle', 'documents'),
            'Guest user_id' => config('documentable.security.allow_guest_user_id', false) ? 'allowed' : 'ignored',
            'Document types' => $this->documentTypeSummary(),
            'Insecure in production' => $this->isInsecureProductionSetup() ? 'yes' : 'no',
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
