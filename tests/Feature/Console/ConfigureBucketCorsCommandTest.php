<?php

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/v2.0.0-feedback.md #5 — sibling to
 * ConfigureBucketLifecycleCommand, which has no test coverage of its actual AWS SDK call
 * either (requires a live/mocked S3 client). This only covers the --origin guard, which
 * runs before the client is ever touched. See
 * docs/implementations/v2.1.0/phase-18-configure-bucket-cors-command.md.
 */
class ConfigureBucketCorsCommandTest extends TestCase
{
    public function test_refuses_to_run_without_at_least_one_origin(): void
    {
        $this->artisan('documents:configure-bucket-cors', ['disk' => 's3'])
            ->expectsOutputToContain('At least one --origin is required')
            ->assertExitCode(1);
    }
}
