<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;

/**
 * Interactive wizard mirroring the reference package's `sequence:install`.
 */
class InstallCommand extends Command
{
    protected $signature = 'documents:install';

    protected $description = 'Publish config/migrations/Boost skill and configure laravel-documentable.';

    public function handle(): int
    {
        $this->info('Installing laravel-documentable...');

        $this->call('vendor:publish', ['--tag' => 'documentable-config']);
        $this->call('vendor:publish', ['--tag' => 'documentable-migrations']);
        $this->call('vendor:publish', ['--tag' => 'documentable-boost-skills']);

        if ($this->confirm('Run migrations now?', true)) {
            $this->call('migrate');
        }

        $appShape = $this->choice(
            "Is this app a session-based monolith (Inertia/Livewire/same-origin SPA) or a separate API/SPA?\n".
            "  monolith: mounts routes under ['web', 'auth'] — \$request->user() populated, CSRF applies.\n".
            "  separate-api: mounts under ['api'] only (default) — wire your own token guard\n".
            '                (e.g. auth:sanctum) into documentable.middleware yourself.',
            ['separate-api', 'monolith'],
            0
        );

        if ($appShape === 'monolith') {
            $this->writeConfigArrayValue('middleware', ['web', 'auth']);
        } else {
            $this->info("Routes stay under ['api'] — add your token guard to config('documentable.middleware') yourself.");
        }

        $etagStrategy = $this->choice(
            "Multipart ETag strategy?\n".
            "  client: fewer round trips, but requires bucket CORS ExposeHeaders: [\"ETag\"].\n".
            '  server-authoritative: no CORS dependency, costs one extra ListParts call per completion.',
            ['server-authoritative', 'client'],
            0
        );
        $this->writeConfigValue('etag_strategy', $etagStrategy);

        $typesMode = $this->choice(
            "Document type catalog?\n".
            "  code-first: define types in config('documentable.types'), synced via documents:sync-types (git-versioned, PR-reviewed, cacheable).\n".
            '  db-only: manage the document_types table directly through your own admin layer (runtime-editable, no deploy needed).',
            ['code-first', 'db-only'],
            0
        );

        if ($typesMode === 'code-first') {
            $this->info("Define your types in config('documentable.types'), then run `php artisan documents:sync-types` after each change — add it to your deploy pipeline alongside `migrate`.");
        } else {
            $this->info("Leave config('documentable.types') empty and manage the document_types table directly.");
        }

        if ($this->confirm('Generate a starter AuthorizesDocumentAccess implementation? (default is permissive — allows everything)', true)) {
            $this->call('documents:make-authorizer');
        }

        $this->info('laravel-documentable installed.');

        return self::SUCCESS;
    }

    /**
     * Best-effort regex rewrite of the published config file so the installer's
     * prompted choice doesn't get silently discarded in favor of the shipped
     * default. Only touches the first match for $key — safe for this file's
     * flat, one-key-per-name shape.
     */
    protected function writeConfigValue(string $key, string $value): void
    {
        $path = config_path('documentable.php');

        if (! file_exists($path)) {
            return;
        }

        $contents = file_get_contents($path);
        $updated = preg_replace(
            "/('{$key}'\\s*=>\\s*)'[^']*'/",
            "\${1}'{$value}'",
            $contents,
            1
        );

        if ($updated !== null) {
            file_put_contents($path, $updated);
        }
    }

    /**
     * Sibling to writeConfigValue() for an array-literal config value written on a
     * single line in the shipped default (e.g. 'middleware' => ['api'],). Same
     * best-effort, first-match-only scoping — safe here since each key appears once.
     */
    protected function writeConfigArrayValue(string $key, array $values): void
    {
        $path = config_path('documentable.php');

        if (! file_exists($path)) {
            return;
        }

        $literal = '['.implode(', ', array_map(fn ($value) => "'{$value}'", $values)).']';

        $contents = file_get_contents($path);
        $updated = preg_replace(
            "/('{$key}'\\s*=>\\s*)\\[[^\\]]*\\]/",
            "\${1}{$literal}",
            $contents,
            1
        );

        if ($updated !== null) {
            file_put_contents($path, $updated);
        }
    }
}
