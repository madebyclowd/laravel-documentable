<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\Upgrade\CodeScanner;

class UpgradeCheckCodeModeTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = dirname(__DIR__, 2).'/Fixtures/UpgradeApps';
    }

    /**
     * The clean tree holds an app's own `'pending'` statuses (a service, a migration, a config banner): none of
     * them is a v2 document API.
     */
    public function test_code_mode_passes_on_clean_fixture_tree(): void
    {
        $this->artisan('documents:upgrade-check', [
            '--code' => true,
            '--path' => $this->fixturesPath.'/clean',
        ])
            ->expectsOutputToContain('No v2 API found.')
            ->assertExitCode(0);
    }

    public function test_code_mode_fails_and_prints_grouped_output_on_dirty_fixture_tree(): void
    {
        $this->artisan('documents:upgrade-check', [
            '--code' => true,
            '--path' => $this->fixturesPath.'/removed-upload-api',
        ])
            ->expectsOutputToContain('app/Services/LegacyUploadService.php:16')
            ->expectsOutputToContain('use: Use `attach(session, owner)` and the `processing` status.')
            ->expectsOutputToContain('see UPGRADE.md#removed-upload-api')
            ->assertExitCode(1);
    }

    public function test_a_rule_can_be_ignored(): void
    {
        $this->artisan('documents:upgrade-check', [
            '--code' => true,
            '--path' => $this->fixturesPath.'/removed-upload-api',
            '--ignore' => ['removed-upload-api'],
        ])
            ->expectsOutputToContain('No v2 API found.')
            ->assertExitCode(0);
    }

    public function test_an_unknown_rule_to_ignore_is_refused(): void
    {
        $this->artisan('documents:upgrade-check', [
            '--code' => true,
            '--path' => $this->fixturesPath.'/clean',
            '--ignore' => ['no-such-rule'],
        ])
            ->expectsOutput('Unknown rule "no-such-rule". Known rules: '.implode(', ', (new CodeScanner)->ruleIds()).'.')
            ->assertExitCode(2);
    }

    public function test_no_flag_runs_data_mode_only(): void
    {
        $this->artisan('documents:upgrade-check')
            ->expectsOutputToContain('No integrity problems found')
            ->assertExitCode(0);
    }
}
