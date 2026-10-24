<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * `documents:sync-types` used to print one line, "N synced", whatever happened. A type that exists in the
 * database but was dropped from config (or a deploy that never ran the command) was invisible. It now says what
 * it created, updated and left alone, and `php artisan about` shows whether config and database agree.
 */
class SyncTypesReportTest extends TestCase
{
    use RefreshDatabase;

    protected function defineTypes(array $codes): void
    {
        config()->set('documentable.types', collect($codes)->mapWithKeys(fn (string $code) => [
            $code => ['name' => ucfirst(strtolower($code)), 'max_size_mb' => 5, 'disk' => 'test_disk', 'path_prefix' => strtolower($code)],
        ])->all());
    }

    public function test_it_reports_created_and_updated_types(): void
    {
        DocumentType::create(['code' => 'EXISTING', 'name' => 'Old name', 'max_size_mb' => 1, 'disk' => 'test_disk', 'path_prefix' => 'existing']);
        $this->defineTypes(['EXISTING', 'FRESH']);

        $this->artisan('documents:sync-types')
            ->expectsOutputToContain('Created: FRESH')
            ->expectsOutputToContain('Updated: EXISTING')
            ->assertExitCode(0);
    }

    public function test_it_lists_database_types_that_config_no_longer_defines(): void
    {
        DocumentType::create(['code' => 'LEGACY', 'name' => 'Legacy', 'max_size_mb' => 1, 'disk' => 'test_disk', 'path_prefix' => 'legacy']);
        $this->defineTypes(['CURRENT']);

        $this->artisan('documents:sync-types')
            ->expectsOutputToContain('In the database but not in config (kept, use --prune to deactivate): LEGACY')
            ->assertExitCode(0);

        $this->assertNull(DocumentType::where('code', 'LEGACY')->first()->deleted_at);
    }

    public function test_prune_deactivates_them_and_says_so(): void
    {
        DocumentType::create(['code' => 'LEGACY', 'name' => 'Legacy', 'max_size_mb' => 1, 'disk' => 'test_disk', 'path_prefix' => 'legacy']);
        $this->defineTypes(['CURRENT']);

        $this->artisan('documents:sync-types', ['--prune' => true])
            ->expectsOutputToContain('1 document type(s) deactivated')
            ->assertExitCode(0);

        $this->assertNotNull(DocumentType::withTrashed()->where('code', 'LEGACY')->first()->deleted_at);
    }

    public function test_an_empty_config_is_called_out_instead_of_reporting_zero_synced(): void
    {
        config()->set('documentable.types', []);

        $this->artisan('documents:sync-types')
            ->expectsOutputToContain("No types are defined in config('documentable.types')")
            ->assertExitCode(0);
    }

    public function test_about_shows_how_config_and_database_compare(): void
    {
        DocumentType::create(['code' => 'LEGACY', 'name' => 'Legacy', 'max_size_mb' => 1, 'disk' => 'test_disk', 'path_prefix' => 'legacy']);
        $this->defineTypes(['CURRENT', 'OTHER']);

        Artisan::call('about', ['--json' => true]);
        $about = json_decode(Artisan::output(), true);

        $this->assertSame('2 in config, 1 active in database, 2 in config not yet synced', $about['documentable']['document_types']);
    }
}
