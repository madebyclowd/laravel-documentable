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
        $this->info("Installing laravel-documentable — this will ask you a few questions, then publish\nconfig/migrations and generate a couple of starter files. Not sure about an answer? The\ndefault (first option, or press Enter) is a safe choice for most apps.");
        $this->newLine();

        $this->call('vendor:publish', ['--tag' => 'documentable-config']);
        $this->call('vendor:publish', ['--tag' => 'documentable-migrations']);

        if ($this->confirm('Run the new database migrations now?', true)) {
            $this->call('migrate');
        }

        $appShape = $this->option('shape');

        if ($appShape === null) {
            if ($this->input->isInteractive()) {
                $appShape = $this->choice(
                    "How does your app handle logging in users? This decides which security setup we\n".
                    "use for the upload routes.\n".
                    "  separate-api (default) — Your frontend is a separate app or domain (e.g. a mobile\n".
                    "                app, or a JS app on another domain) calling this Laravel app only as\n".
                    "                an API, usually with an API token like Laravel Sanctum. If that's not\n".
                    "                your setup, pick monolith below.\n".
                    '  monolith — A typical Laravel app where pages and API calls share the same domain'."\n".
                    '             and the same login session (Blade, Inertia, Livewire, or a same-origin'."\n".
                    '             SPA). Most "normal" Laravel apps should pick this.',
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
            $this->info('Done — upload routes now require a logged-in user, same as the rest of your app.');
        } else {
            $this->info("Routes stay under ['api'] — add your own API token guard (e.g. Sanctum) to config('documentable.middleware') whenever you're ready to lock this down.");
        }

        $etagStrategy = $this->option('etag-strategy');

        if ($etagStrategy === null) {
            $etagStrategy = $this->input->isInteractive()
                ? $this->choice(
                    "Which method should we use to double-check large (multipart) uploads finished\n".
                    "correctly?\n".
                    "  server-authoritative (default) — Works on any bucket, no extra setup needed. Adds\n".
                    "                        one small extra check when a big upload finishes (usually\n".
                    "                        not noticeable).\n".
                    "  client — Skips that extra check (very slightly faster), but only works if your\n".
                    '           bucket (S3/R2/Spaces/MinIO) has CORS configured to expose the ETag header.'."\n".
                    '           Only pick this if you already set that up, or plan to run'."\n".
                    '           `documents:configure-bucket-cors` afterward.',
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
                    "How do you want to manage upload \"types\" (e.g. \"invoice\", \"profile-photo\")? A type\n".
                    "sets rules like max file size and which file formats are allowed.\n".
                    "  code-first (default) — Define types in config/documentable.php (version-controlled\n".
                    "              with the rest of your code), then run `documents:sync-types` to save\n".
                    "              them to the database. Recommended for most apps.\n".
                    '  db-only — Skip the config file; manage types directly in the database yourself'."\n".
                    '            (e.g. through your own admin panel). Only pick this if you already have'."\n".
                    '            a way to do that.',
                    ['code-first', 'db-only'],
                    0
                )
                : 'code-first';
        } elseif (! in_array($typesMode, ['code-first', 'db-only'], true)) {
            $this->error("Invalid --types value: '{$typesMode}'. Expected 'code-first' or 'db-only'.");

            return self::FAILURE;
        }

        if ($typesMode === 'code-first') {
            $this->info("Next step for types: open config/documentable.php, add your types under the 'types'\nkey, then run `php artisan documents:sync-types` (also add that command to your deploy\npipeline, right alongside `migrate`, so it runs on every deploy).");
        } else {
            $this->info("Leave config('documentable.types') empty and manage the document_types table directly.");
        }

        $generatedAuthorizer = false;

        if ($this->confirm(
            "Generate a starting point for controlling who's allowed to upload/view/delete\n".
            "documents? Without this, the default setup lets ANYONE upload, view, or delete ANY\n".
            'document — fine for local development, not fine for production.',
            true
        )) {
            $this->call('documents:make-authorizer');
            $generatedAuthorizer = true;
        }

        $this->newLine();
        $this->info('✔ laravel-documentable is installed. Here is what to do next:');
        $this->line('  1. Attach a model: run `php artisan documents:attach-model YourModel` (e.g. Invoice).');
        $this->line('  2. Define at least one upload type in config/documentable.php, then run `php artisan documents:sync-types`.');

        if ($generatedAuthorizer) {
            $this->line('  3. Open the authorizer file we just generated in app/Documentable and fill in your real ownership/role checks.');
        } else {
            $this->line('  3. Before going to production, run `php artisan documents:make-authorizer` — right now ANY user can upload/view/delete ANY document.');
        }

        $this->line('  4. Upload your first file: $document = app(\MadeByClowd\Documentable\Services\DocumentService::class)->upload($file, $type, $owner);');
        $this->line('See the README for a full walkthrough and every HTTP route the package ships.');

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
