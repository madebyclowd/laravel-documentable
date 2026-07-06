<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;

/**
 * Interactive wizard mirroring the reference package's `sequence:install`.
 */
class InstallCommand extends Command
{
    protected $signature = 'documents:install
        {--shape= : App shape — separate-api or monolith. Skips the interactive prompt when set.}
        {--etag-strategy= : Multipart ETag strategy — server-authoritative or client. Skips the interactive prompt when set.}
        {--types= : Document type catalog mode — code-first or db-only. Skips the interactive prompt when set.}';

    protected $description = 'Publish config/migrations and configure laravel-documentable.';

    public function handle(): int
    {
        $this->info('Installing laravel-documentable...');

        $this->call('vendor:publish', ['--tag' => 'documentable-config']);
        $this->call('vendor:publish', ['--tag' => 'documentable-migrations']);

        if ($this->confirm('Run migrations now?', true)) {
            $this->call('migrate');
        }

        $appShape = $this->option('shape');

        if ($appShape === null) {
            if ($this->input->isInteractive()) {
                $appShape = $this->choice(
                    "Is this app a session-based monolith (Inertia/Livewire/same-origin SPA) or a separate API/SPA?\n".
                    "  monolith: mounts routes under ['web', 'auth'] — \$request->user() populated, CSRF applies.\n".
                    "  separate-api: mounts under ['api'] only (default) — wire your own token guard\n".
                    '                (e.g. auth:sanctum) into documentable.middleware yourself.',
                    ['separate-api', 'monolith'],
                    0
                );
            } else {
                $appShape = 'separate-api';
                $this->warn(
                    "No --shape given and running non-interactively — defaulting to 'separate-api'. Routes stay ".
                    "under ['api'] with no session/auth: \$request->user() will be null on every request. Pass ".
                    '--shape=monolith explicitly if this is a session-based app, or --shape=separate-api to silence this warning.'
                );
            }
        } elseif (! in_array($appShape, ['separate-api', 'monolith'], true)) {
            $this->error("Invalid --shape value: '{$appShape}'. Expected 'separate-api' or 'monolith'.");

            return self::FAILURE;
        }

        if ($appShape === 'monolith') {
            $this->writeConfigArrayValue('middleware', ['web', 'auth']);
        } else {
            $this->info("Routes stay under ['api'] — add your token guard to config('documentable.middleware') yourself.");
        }

        $etagStrategy = $this->option('etag-strategy');

        if ($etagStrategy === null) {
            $etagStrategy = $this->input->isInteractive()
                ? $this->choice(
                    "Multipart ETag strategy?\n".
                    "  client: fewer round trips, but requires bucket CORS ExposeHeaders: [\"ETag\"].\n".
                    '  server-authoritative: no CORS dependency, costs one extra ListParts call per completion.',
                    ['server-authoritative', 'client'],
                    0
                )
                : 'server-authoritative';
        } elseif (! in_array($etagStrategy, ['server-authoritative', 'client'], true)) {
            $this->error("Invalid --etag-strategy value: '{$etagStrategy}'. Expected 'server-authoritative' or 'client'.");

            return self::FAILURE;
        }
        $this->writeConfigValue('etag_strategy', $etagStrategy);

        $typesMode = $this->option('types');

        if ($typesMode === null) {
            $typesMode = $this->input->isInteractive()
                ? $this->choice(
                    "Document type catalog?\n".
                    "  code-first: define types in config('documentable.types'), synced via documents:sync-types (git-versioned, PR-reviewed, cacheable).\n".
                    '  db-only: manage the document_types table directly through your own admin layer (runtime-editable, no deploy needed).',
                    ['code-first', 'db-only'],
                    0
                )
                : 'code-first';
        } elseif (! in_array($typesMode, ['code-first', 'db-only'], true)) {
            $this->error("Invalid --types value: '{$typesMode}'. Expected 'code-first' or 'db-only'.");

            return self::FAILURE;
        }

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
