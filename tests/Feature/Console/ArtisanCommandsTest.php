<?php

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Console\Commands\InstallCommand;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class ArtisanCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_types_creates_document_types_from_config(): void
    {
        config()->set('documentable.types', [
            'invoice' => [
                'name' => 'Invoice',
                'max_size_mb' => 10,
                'allowed_mimes' => ['application/pdf'],
                'disk' => 'local',
                'path_prefix' => 'invoices',
            ],
        ]);

        $this->artisan('documents:sync-types')->assertExitCode(0);

        $type = DocumentType::where('code', 'invoice')->first();
        $this->assertNotNull($type);
        $this->assertSame('Invoice', $type->name);
        $this->assertSame(10, $type->max_size_mb);
    }

    public function test_sync_types_updates_existing_type_on_rerun(): void
    {
        config()->set('documentable.types', [
            'invoice' => ['name' => 'Invoice', 'max_size_mb' => 10, 'disk' => 'local', 'path_prefix' => 'invoices'],
        ]);
        $this->artisan('documents:sync-types')->assertExitCode(0);

        config()->set('documentable.types.invoice.max_size_mb', 20);
        $this->artisan('documents:sync-types')->assertExitCode(0);

        $this->assertSame(1, DocumentType::where('code', 'invoice')->count());
        $this->assertSame(20, DocumentType::where('code', 'invoice')->first()->max_size_mb);
    }

    public function test_sync_types_prune_deactivates_missing_types(): void
    {
        DocumentType::create([
            'code' => 'stale', 'name' => 'Stale', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);

        config()->set('documentable.types', []);

        $this->artisan('documents:sync-types --prune')->assertExitCode(0);

        $this->assertTrue(DocumentType::withTrashed()->where('code', 'stale')->first()->trashed());
    }

    public function test_sync_types_revives_previously_deactivated_type(): void
    {
        $type = DocumentType::create([
            'code' => 'invoice', 'name' => 'Invoice', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);
        $type->delete();

        config()->set('documentable.types', [
            'invoice' => ['name' => 'Invoice', 'max_size_mb' => 15, 'disk' => 'local', 'path_prefix' => 'invoices'],
        ]);

        $this->artisan('documents:sync-types')->assertExitCode(0);

        $revived = DocumentType::where('code', 'invoice')->first();
        $this->assertNotNull($revived);
        $this->assertFalse($revived->trashed());
        $this->assertSame(15, $revived->max_size_mb);
    }

    public function test_list_command_reports_document_type_counts(): void
    {
        $type = DocumentType::create([
            'code' => 'invoice', 'name' => 'Invoice', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);
        $owner = TestModel::create(['name' => 'owner']);
        Document::create([
            'storage_file_id' => StorageFile::create([
                'file_hash' => 'abc', 'disk' => 'local', 'path' => 'x/1', 'mime_type' => 'text/plain', 'size_bytes' => 1,
            ])->id,
            'document_type_id' => $type->id,
            'document_group_id' => (string) Str::uuid(),
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
            'client_filename' => 'a.txt',
            'version' => 1,
            'is_latest' => true,
            'latest_marker' => (string) Str::uuid(),
        ]);

        $this->artisan('documents:list')
            ->expectsOutputToContain('invoice')
            ->assertExitCode(0);
    }

    public function test_verify_command_detects_and_repairs_drift(): void
    {
        $type = DocumentType::create([
            'code' => 'invoice', 'name' => 'Invoice', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);
        $owner = TestModel::create(['name' => 'owner']);
        $storageFile = StorageFile::create([
            'file_hash' => 'abc', 'disk' => 'local', 'path' => 'x/1', 'mime_type' => 'text/plain', 'size_bytes' => 1,
        ]);

        // Drifted on purpose: is_latest = true but latest_marker = null, the exact
        // inconsistency the reaper/service layer should never produce.
        $document = Document::create([
            'storage_file_id' => $storageFile->id,
            'document_type_id' => $type->id,
            'document_group_id' => (string) Str::uuid(),
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
            'client_filename' => 'a.txt',
            'version' => 1,
            'is_latest' => true,
            'latest_marker' => null,
        ]);

        $this->artisan('documents:verify')
            ->expectsOutputToContain('1 document(s)')
            ->assertExitCode(0);

        $this->assertTrue($document->fresh()->is_latest);

        $this->artisan('documents:verify --repair')->assertExitCode(0);

        $this->assertFalse($document->fresh()->is_latest);
    }

    public function test_verify_command_reports_clean_when_no_drift(): void
    {
        $this->artisan('documents:verify')
            ->expectsOutputToContain('No latest_marker')
            ->assertExitCode(0);
    }

    public function test_install_command_runs_end_to_end_without_error(): void
    {
        $this->artisan('documents:install')
            ->expectsConfirmation('Run the new database migrations now?', 'no')
            ->expectsChoice(
                "How does your app handle logging in users? This decides which security setup we\n".
                "use for the upload routes.\n".
                "  separate-api (default) — Your frontend is a separate app or domain (e.g. a mobile\n".
                "                app, or a JS app on another domain) calling this Laravel app only as\n".
                "                an API, usually with an API token like Laravel Sanctum. If that's not\n".
                "                your setup, pick monolith below.\n".
                '  monolith — A typical Laravel app where pages and API calls share the same domain'."\n".
                '             and the same login session (Blade, Inertia, Livewire, or a same-origin'."\n".
                '             SPA). Most "normal" Laravel apps should pick this.',
                'separate-api',
                ['separate-api', 'monolith']
            )
            ->expectsChoice(
                "Which method should we use to double-check large (multipart) uploads finished\n".
                "correctly?\n".
                "  server-authoritative (default) — Works on any bucket, no extra setup needed. Adds\n".
                "                        one small extra check when a big upload finishes (usually\n".
                "                        not noticeable).\n".
                "  client — Skips that extra check (very slightly faster), but only works if your\n".
                '           bucket (S3/R2/Spaces/MinIO) has CORS configured to expose the ETag header.'."\n".
                '           Only pick this if you already set that up, or plan to run'."\n".
                '           `documents:configure-bucket-cors` afterward.',
                'server-authoritative',
                ['server-authoritative', 'client']
            )
            ->expectsChoice(
                "How do you want to manage upload \"types\" (e.g. \"invoice\", \"profile-photo\")? A type\n".
                "sets rules like max file size and which file formats are allowed.\n".
                "  code-first (default) — Define types in config/documentable.php (version-controlled\n".
                "              with the rest of your code), then run `documents:sync-types` to save\n".
                "              them to the database. Recommended for most apps.\n".
                '  db-only — Skip the config file; manage types directly in the database yourself'."\n".
                '            (e.g. through your own admin panel). Only pick this if you already have'."\n".
                '            a way to do that.',
                'code-first',
                ['code-first', 'db-only']
            )
            ->expectsConfirmation(
                "Generate a starting point for controlling who's allowed to upload/view/delete\n".
                "documents? Without this, the default setup lets ANYONE upload, view, or delete ANY\n".
                'document — fine for local development, not fine for production.',
                'no'
            )
            ->assertExitCode(0);
    }

    public function test_install_command_monolith_choice_writes_middleware_config(): void
    {
        // Force a fresh publish — vendor:publish (no --force) is a no-op once the
        // destination exists, so a stale copy from an earlier run/session would
        // otherwise predate this config key and make the regex write-back a silent
        // no-op. Testbench's config_path() is a shared, regenerable skeleton path.
        @unlink(config_path('documentable.php'));

        $this->artisan('documents:install')
            ->expectsConfirmation('Run the new database migrations now?', 'no')
            ->expectsChoice(
                "How does your app handle logging in users? This decides which security setup we\n".
                "use for the upload routes.\n".
                "  separate-api (default) — Your frontend is a separate app or domain (e.g. a mobile\n".
                "                app, or a JS app on another domain) calling this Laravel app only as\n".
                "                an API, usually with an API token like Laravel Sanctum. If that's not\n".
                "                your setup, pick monolith below.\n".
                '  monolith — A typical Laravel app where pages and API calls share the same domain'."\n".
                '             and the same login session (Blade, Inertia, Livewire, or a same-origin'."\n".
                '             SPA). Most "normal" Laravel apps should pick this.',
                'monolith',
                ['separate-api', 'monolith']
            )
            ->expectsChoice(
                "Which method should we use to double-check large (multipart) uploads finished\n".
                "correctly?\n".
                "  server-authoritative (default) — Works on any bucket, no extra setup needed. Adds\n".
                "                        one small extra check when a big upload finishes (usually\n".
                "                        not noticeable).\n".
                "  client — Skips that extra check (very slightly faster), but only works if your\n".
                '           bucket (S3/R2/Spaces/MinIO) has CORS configured to expose the ETag header.'."\n".
                '           Only pick this if you already set that up, or plan to run'."\n".
                '           `documents:configure-bucket-cors` afterward.',
                'server-authoritative',
                ['server-authoritative', 'client']
            )
            ->expectsChoice(
                "How do you want to manage upload \"types\" (e.g. \"invoice\", \"profile-photo\")? A type\n".
                "sets rules like max file size and which file formats are allowed.\n".
                "  code-first (default) — Define types in config/documentable.php (version-controlled\n".
                "              with the rest of your code), then run `documents:sync-types` to save\n".
                "              them to the database. Recommended for most apps.\n".
                '  db-only — Skip the config file; manage types directly in the database yourself'."\n".
                '            (e.g. through your own admin panel). Only pick this if you already have'."\n".
                '            a way to do that.',
                'code-first',
                ['code-first', 'db-only']
            )
            ->expectsConfirmation(
                "Generate a starting point for controlling who's allowed to upload/view/delete\n".
                "documents? Without this, the default setup lets ANYONE upload, view, or delete ANY\n".
                'document — fine for local development, not fine for production.',
                'no'
            )
            ->assertExitCode(0);

        $published = file_get_contents(config_path('documentable.php'));
        $this->assertStringContainsString("'middleware' => ['web', 'auth']", $published);

        // Leave the shared skeleton unpublished again for whichever test runs next.
        @unlink(config_path('documentable.php'));
    }

    public function test_install_command_shape_flag_skips_prompt_under_no_interaction(): void
    {
        @unlink(config_path('documentable.php'));

        $this->artisan('documents:install', ['--no-interaction' => true, '--shape' => 'monolith'])
            ->expectsConfirmation('Run the new database migrations now?', 'no')
            ->expectsConfirmation(
                "Generate a starting point for controlling who's allowed to upload/view/delete\n".
                "documents? Without this, the default setup lets ANYONE upload, view, or delete ANY\n".
                'document — fine for local development, not fine for production.',
                'no'
            )
            ->assertExitCode(0);

        $published = file_get_contents(config_path('documentable.php'));
        $this->assertStringContainsString("'middleware' => ['web', 'auth']", $published);

        @unlink(config_path('documentable.php'));
    }

    public function test_install_command_no_interaction_without_shape_warns_and_keeps_unsafe_default(): void
    {
        @unlink(config_path('documentable.php'));

        $this->artisan('documents:install', ['--no-interaction' => true])
            ->expectsConfirmation('Run the new database migrations now?', 'no')
            ->expectsConfirmation(
                "Generate a starting point for controlling who's allowed to upload/view/delete\n".
                "documents? Without this, the default setup lets ANYONE upload, view, or delete ANY\n".
                'document — fine for local development, not fine for production.',
                'no'
            )
            ->expectsOutputToContain("defaulting to 'separate-api'")
            ->assertExitCode(0);

        $published = file_get_contents(config_path('documentable.php'));
        $this->assertStringContainsString("'middleware' => ['api']", $published);

        @unlink(config_path('documentable.php'));
    }

    public function test_install_command_invalid_shape_option_fails_fast(): void
    {
        $this->artisan('documents:install', ['--no-interaction' => true, '--shape' => 'bogus'])
            ->expectsConfirmation('Run the new database migrations now?', 'no')
            ->assertExitCode(1);
    }

    public function test_install_command_invalid_etag_strategy_option_fails_fast(): void
    {
        $this->artisan('documents:install', ['--no-interaction' => true, '--shape' => 'separate-api', '--etag-strategy' => 'bogus'])
            ->expectsConfirmation('Run the new database migrations now?', 'no')
            ->assertExitCode(1);
    }

    public function test_install_command_invalid_types_option_fails_fast(): void
    {
        $this->artisan('documents:install', [
            '--no-interaction' => true,
            '--shape' => 'separate-api',
            '--etag-strategy' => 'server-authoritative',
            '--types' => 'bogus',
        ])
            ->expectsConfirmation('Run the new database migrations now?', 'no')
            ->assertExitCode(1);
    }

    public function test_install_command_db_only_types_migrates_and_generates_authorizer_when_confirmed(): void
    {
        @unlink(config_path('documentable.php'));

        $fakeAppPath = sys_get_temp_dir().'/documentable-install-test-'.uniqid();
        File::ensureDirectoryExists($fakeAppPath);
        $this->app->getNamespace();
        $this->app->useAppPath($fakeAppPath);

        try {
            $this->artisan('documents:install')
                ->expectsConfirmation('Run the new database migrations now?', 'yes')
                ->expectsChoice(
                    "How does your app handle logging in users? This decides which security setup we\n".
                    "use for the upload routes.\n".
                    "  separate-api (default) — Your frontend is a separate app or domain (e.g. a mobile\n".
                    "                app, or a JS app on another domain) calling this Laravel app only as\n".
                    "                an API, usually with an API token like Laravel Sanctum. If that's not\n".
                    "                your setup, pick monolith below.\n".
                    '  monolith — A typical Laravel app where pages and API calls share the same domain'."\n".
                    '             and the same login session (Blade, Inertia, Livewire, or a same-origin'."\n".
                    '             SPA). Most "normal" Laravel apps should pick this.',
                    'separate-api',
                    ['separate-api', 'monolith']
                )
                ->expectsChoice(
                    "Which method should we use to double-check large (multipart) uploads finished\n".
                    "correctly?\n".
                    "  server-authoritative (default) — Works on any bucket, no extra setup needed. Adds\n".
                    "                        one small extra check when a big upload finishes (usually\n".
                    "                        not noticeable).\n".
                    "  client — Skips that extra check (very slightly faster), but only works if your\n".
                    '           bucket (S3/R2/Spaces/MinIO) has CORS configured to expose the ETag header.'."\n".
                    '           Only pick this if you already set that up, or plan to run'."\n".
                    '           `documents:configure-bucket-cors` afterward.',
                    'server-authoritative',
                    ['server-authoritative', 'client']
                )
                ->expectsChoice(
                    "How do you want to manage upload \"types\" (e.g. \"invoice\", \"profile-photo\")? A type\n".
                    "sets rules like max file size and which file formats are allowed.\n".
                    "  code-first (default) — Define types in config/documentable.php (version-controlled\n".
                    "              with the rest of your code), then run `documents:sync-types` to save\n".
                    "              them to the database. Recommended for most apps.\n".
                    '  db-only — Skip the config file; manage types directly in the database yourself'."\n".
                    '            (e.g. through your own admin panel). Only pick this if you already have'."\n".
                    '            a way to do that.',
                    'db-only',
                    ['code-first', 'db-only']
                )
                ->expectsConfirmation(
                    "Generate a starting point for controlling who's allowed to upload/view/delete\n".
                    "documents? Without this, the default setup lets ANYONE upload, view, or delete ANY\n".
                    'document — fine for local development, not fine for production.',
                    'yes'
                )
                ->expectsOutputToContain("Leave config('documentable.types') empty and manage the document_types table directly.")
                ->expectsOutputToContain('Open the authorizer file we just generated in app/Documentable and fill in your real ownership/role checks.')
                ->assertExitCode(0);

            $this->assertFileExists($fakeAppPath.'/Documentable/AppDocumentAuthorizer.php');
        } finally {
            File::deleteDirectory($fakeAppPath);
            @unlink(config_path('documentable.php'));
        }
    }

    public function test_write_config_value_is_a_noop_when_config_file_missing(): void
    {
        @unlink(config_path('documentable.php'));

        $command = $this->app->make(InstallCommand::class);
        $method = new \ReflectionMethod($command, 'writeConfigValue');
        $method->setAccessible(true);
        $method->invoke($command, 'etag_strategy', 'client');

        $this->assertFileDoesNotExist(config_path('documentable.php'));
    }

    public function test_write_config_array_value_is_a_noop_when_config_file_missing(): void
    {
        @unlink(config_path('documentable.php'));

        $command = $this->app->make(InstallCommand::class);
        $method = new \ReflectionMethod($command, 'writeConfigArrayValue');
        $method->setAccessible(true);
        $method->invoke($command, 'middleware', ['web', 'auth']);

        $this->assertFileDoesNotExist(config_path('documentable.php'));
    }

    public function test_list_command_reports_when_no_document_types_registered(): void
    {
        $this->artisan('documents:list')
            ->expectsOutputToContain('No document types registered yet.')
            ->assertExitCode(0);
    }
}
