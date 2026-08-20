<?php

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Sibling to ConfigureBucketCorsCommandTest — exercises the real putBucketLifecycleConfiguration()
 * SDK call against a real S3Client whose HTTP handler is swapped for Aws\MockHandler, so no
 * network call or real bucket is needed.
 */
class ConfigureBucketLifecycleCommandTest extends TestCase
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

    public function test_configures_lifecycle_with_default_days(): void
    {
        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result([]);
        });

        $this->setUpMockedDisk($handler);

        $this->artisan('documents:configure-bucket-lifecycle', ['disk' => 's3'])
            ->expectsOutputToContain('Configured AbortIncompleteMultipartUpload (3 days) on disk [s3], bucket [test-bucket].')
            ->assertExitCode(0);

        $rule = $captured['LifecycleConfiguration']['Rules'][0];
        $this->assertSame('test-bucket', $captured['Bucket']);
        $this->assertSame('documentable-abort-incomplete-multipart-uploads', $rule['ID']);
        $this->assertSame('Enabled', $rule['Status']);
        $this->assertSame(3, $rule['AbortIncompleteMultipartUpload']['DaysAfterInitiation']);
    }

    public function test_configures_lifecycle_with_custom_days_option(): void
    {
        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result([]);
        });

        $this->setUpMockedDisk($handler);

        $this->artisan('documents:configure-bucket-lifecycle', ['disk' => 's3', '--days' => 7])
            ->expectsOutputToContain('Configured AbortIncompleteMultipartUpload (7 days)')
            ->assertExitCode(0);

        $rule = $captured['LifecycleConfiguration']['Rules'][0];
        $this->assertSame(7, $rule['AbortIncompleteMultipartUpload']['DaysAfterInitiation']);
    }
}
