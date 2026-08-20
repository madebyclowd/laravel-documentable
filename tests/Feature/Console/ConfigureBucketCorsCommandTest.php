<?php

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Support\Facades\Http;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/v2.0.0-feedback.md #5. The --origin guard
 * (which runs before the S3 client is ever touched) needs no mocking; the actual
 * putBucketCors() call is exercised against a real S3Client whose HTTP handler is
 * swapped for Aws\MockHandler — see docs/implementations/v2.1.0/phase-18-configure-bucket-cors-command.md.
 */
class ConfigureBucketCorsCommandTest extends TestCase
{
    protected function setUpMockedDisk(MockHandler $handler): void
    {
        config()->set('filesystems.disks.s3', [
            'driver' => 's3',
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'handler' => $handler,
        ]);
    }

    public function test_refuses_to_run_without_at_least_one_origin(): void
    {
        $this->artisan('documents:configure-bucket-cors', ['disk' => 's3'])
            ->expectsOutputToContain('At least one --origin is required')
            ->assertExitCode(1);
    }

    public function test_configures_cors_without_etag_exposure_by_default(): void
    {
        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result([]);
        });

        $this->setUpMockedDisk($handler);

        $this->artisan('documents:configure-bucket-cors', [
            'disk' => 's3',
            '--origin' => ['https://app.example.com'],
        ])
            ->expectsOutputToContain('Configured CORS on disk [s3], bucket [test-bucket], origins: https://app.example.com')
            ->assertExitCode(0);

        $rule = $captured['CORSConfiguration']['CORSRules'][0];
        $this->assertSame(['https://app.example.com'], $rule['AllowedOrigins']);
        $this->assertSame(['GET', 'PUT'], $rule['AllowedMethods']);
        $this->assertSame([], $rule['ExposeHeaders']);
    }

    public function test_configures_cors_exposing_etag_when_client_strategy_configured(): void
    {
        config()->set('documentable.multipart.etag_strategy', 'client');

        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result([]);
        });

        $this->setUpMockedDisk($handler);

        $this->artisan('documents:configure-bucket-cors', [
            'disk' => 's3',
            '--origin' => ['https://app.example.com', 'https://other.example.com'],
        ])
            ->expectsOutputToContain('ExposeHeaders: ETag, per etag_strategy=client')
            ->assertExitCode(0);

        $rule = $captured['CORSConfiguration']['CORSRules'][0];
        $this->assertSame(['https://app.example.com', 'https://other.example.com'], $rule['AllowedOrigins']);
        $this->assertSame(['ETag'], $rule['ExposeHeaders']);
    }

    public function test_verify_option_reports_success_on_a_successful_smoke_test_put(): void
    {
        $handler = new MockHandler;
        // putBucketCors(), then the post-smoke-test deleteObject() — both go through
        // the same mocked S3Client.
        $handler->append(new Result([]), new Result([]));

        $this->setUpMockedDisk($handler);

        // temporaryUploadUrl() on the real S3 driver signs locally (no HTTP call), so
        // only the two S3Client calls above need the SDK mock; the smoke-test PUT
        // itself goes through Laravel's HTTP client fake below.
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('documents:configure-bucket-cors', [
            'disk' => 's3',
            '--origin' => ['https://app.example.com'],
            '--verify' => true,
        ])
            ->expectsOutputToContain('Smoke test PUT succeeded')
            ->assertExitCode(0);
    }

    public function test_verify_option_reports_failure_on_a_failed_smoke_test_put(): void
    {
        $handler = new MockHandler;
        $handler->append(new Result([]), new Result([]));

        $this->setUpMockedDisk($handler);

        Http::fake(['*' => Http::response('forbidden', 403)]);

        $this->artisan('documents:configure-bucket-cors', [
            'disk' => 's3',
            '--origin' => ['https://app.example.com'],
            '--verify' => true,
        ])
            ->expectsOutputToContain('Smoke test PUT failed: HTTP 403')
            ->assertExitCode(1);
    }
}
