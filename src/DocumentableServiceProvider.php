<?php

namespace MadeByClowd\Documentable;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class DocumentableServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/documentable.php', 'documentable');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('documentable.load_migrations', true) || $this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/documentable.php' => config_path('documentable.php'),
            ], 'documentable-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'documentable-migrations');

            $this->publishes([
                __DIR__.'/../resources/boost/skills' => base_path('.github/skills'),
            ], 'documentable-boost-skills');

            $this->commands([
                // Console commands registered as later phases add them.
            ]);

            Event::listen(
                CommandFinished::class,
                function (CommandFinished $event) {
                    if (in_array($event->command, ['boost:install', 'boost:update'])) {
                        $this->autoPublishBoostSkills();
                    }
                }
            );
        }
    }

    /**
     * Automatically copy Boost skill markdown to project repository.
     */
    protected function autoPublishBoostSkills(): void
    {
        $source = __DIR__.'/../resources/boost/skills/laravel-documentable/SKILL.md';
        if (! file_exists($source)) {
            return;
        }

        $targets = [];
        if (is_dir(base_path('.github/skills'))) {
            $targets[] = base_path('.github/skills/laravel-documentable/SKILL.md');
        }
        if (is_dir(base_path('.ai/skills'))) {
            $targets[] = base_path('.ai/skills/laravel-documentable/SKILL.md');
        }

        if (empty($targets)) {
            $targets[] = base_path('.github/skills/laravel-documentable/SKILL.md');
        }

        foreach ($targets as $destination) {
            if (! is_dir(dirname($destination))) {
                mkdir(dirname($destination), 0755, true);
            }
            copy($source, $destination);
        }

        $boostJsonPath = base_path('boost.json');
        if (file_exists($boostJsonPath)) {
            $boostJson = json_decode(file_get_contents($boostJsonPath), true);
            if (is_array($boostJson) && isset($boostJson['skills'])) {
                if (! in_array('laravel-documentable', $boostJson['skills'])) {
                    $boostJson['skills'][] = 'laravel-documentable';
                    file_put_contents(
                        $boostJsonPath,
                        json_encode($boostJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );
                }
            }
        }
    }
}
