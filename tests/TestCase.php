<?php

namespace MadeByClowd\Documentable\Tests;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\DocumentableServiceProvider;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Stand-in "documentable" owner model used across feature tests —
        // not part of the package, just a fixture to attach documents to.
        if (! Schema::hasTable('test_models')) {
            Schema::create('test_models', function ($table) {
                $table->id();
                $table->string('name')->nullable();
                $table->timestamps();
            });
        }

        // Mirrors the recommended consumer setup (Relation::morphMap(), see
        // v2.0.0/phase-9-documentable-type-allowlist.md) so the default test suite
        // exercises the "correctly configured" path — raw-FQCN-as-documentable_type
        // is exercised separately in DocumentableTypeAllowlistTest, not here.
        Relation::morphMap(['test_model' => TestModel::class]);
    }

    /**
     * Get package providers.
     *
     * @param  Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            DocumentableServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param  Application  $app
     */
    /**
     * Driver is selectable via DB_CONNECTION so CI can run the same suite against
     * sqlite/mysql/pgsql (package-plan.md §5 — the uniqueness/versioning logic is
     * exactly the thing most likely to silently pass on one engine and fail on
     * another). Defaults to the fast in-memory sqlite path for local development.
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', match (env('DB_CONNECTION', 'sqlite')) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'documentable_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'documentable_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'postgres'),
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        });

        $app['config']->set('documentable.load_migrations', true);
    }

    /**
     * Storage::fake()'s local disk only started faking temporaryUploadUrl()
     * once Laravel added buildTemporaryUploadUrlsUsing() — Laravel 11's
     * FilesystemAdapter class doesn't have that method at all, so any fake
     * disk there throws "This driver does not support creating temporary
     * upload URLs." on createPresignedUpload(). A real S3 disk always
     * supports temporaryUploadUrl() regardless of Laravel version, so this
     * is a gap in what the test double can fake on old Laravel, not a
     * package bug — skip rather than weaken the assertion.
     */
    protected function skipUnlessFakeDiskSupportsUploadUrls(string $disk = 'test_disk'): void
    {
        if (! method_exists(Storage::disk($disk), 'buildTemporaryUploadUrlsUsing')) {
            $this->markTestSkipped('Storage::fake() cannot fake temporaryUploadUrl() before Laravel 12 (buildTemporaryUploadUrlsUsing() was added later); the real S3 driver always supports this in production.');
        }
    }
}
