<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Provider;

use Illuminate\Support\Facades\Artisan;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Booting in production with the routes mounted and the allow-everything authorizer is almost certainly a
 * mistake, but a patch release must not turn it into a boot failure. It logs one warning and shows up in
 * `php artisan about` instead.
 */
class InsecureProductionWarningTest extends TestCase
{
    protected string $logFile;

    protected function defineEnvironment($app): void
    {
        $this->logFile = sys_get_temp_dir().'/documentable-warning-'.uniqid().'.log';

        $app->detectEnvironment(fn () => 'production');
        $app['config']->set('logging.default', 'single');
        $app['config']->set('logging.channels.single.path', $this->logFile);
    }

    /**
     * This test needs no database, and `migrate` refuses to run unattended in a production environment.
     */
    protected function defineDatabaseMigrations(): void {}

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    public function test_boot_logs_a_single_warning_and_does_not_throw(): void
    {
        $log = (string) file_get_contents($this->logFile);

        $this->assertSame(1, substr_count($log, 'Documentable is mounted with the permissive authorizer'));
    }

    public function test_about_reports_the_authorization_setup(): void
    {
        Artisan::call('about', ['--json' => true]);
        $about = json_decode(Artisan::output(), true);

        $this->assertSame(
            'MadeByClowd\Documentable\Defaults\PermissiveDocumentAuthorizer',
            $about['documentable']['authorizer']
        );
        $this->assertSame('yes', $about['documentable']['insecure_in_production'] ?? null);
    }
}
