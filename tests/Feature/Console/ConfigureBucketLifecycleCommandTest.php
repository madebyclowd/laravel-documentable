<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
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

    public function test_configures_lifecycle_with_default_days_and_scratch_rule(): void
    {
        $handler = new MockHandler;
        $captured = null;

        // getBucketLifecycleConfiguration returns NoSuchLifecycleConfiguration
        $handler->append(fn (CommandInterface $command) => new S3Exception('none', $command, ['code' => 'NoSuchLifecycleConfiguration']));

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result([]);
        });

        $this->setUpMockedDisk($handler);

        $this->artisan('documents:configure-bucket-lifecycle', ['disk' => 's3'])
            ->expectsOutputToContain('Configured lifecycle rules on disk [s3], bucket [test-bucket].')
            ->assertExitCode(0);

        $rules = $captured['LifecycleConfiguration']['Rules'];
        $this->assertCount(2, $rules);

        $rule1 = $rules[0];
        $this->assertSame('documentable-abort-incomplete-multipart-uploads', $rule1['ID']);
        $this->assertSame('Enabled', $rule1['Status']);
        $this->assertSame(3, $rule1['AbortIncompleteMultipartUpload']['DaysAfterInitiation']);

        $rule2 = $rules[1];
        $this->assertSame('documentable-expire-doctor-scratch', $rule2['ID']);
        $this->assertSame('Enabled', $rule2['Status']);
        $this->assertSame('documentable-doctor/', $rule2['Filter']['Prefix']);
        $this->assertSame(1, $rule2['Expiration']['Days']);
    }

    public function test_refuses_a_disk_that_is_not_s3(): void
    {
        config()->set('filesystems.disks.plain_local', ['driver' => 'local', 'root' => sys_get_temp_dir()]);

        $this->artisan('documents:configure-bucket-lifecycle', ['disk' => 'plain_local'])
            ->expectsOutputToContain('Disk [plain_local] is not an S3 disk.')
            ->assertExitCode(1);
    }

    public function test_an_error_reading_the_existing_rules_stops_the_command_before_anything_is_written(): void
    {
        $handler = new MockHandler;
        $handler->append(fn (CommandInterface $command) => new S3Exception('denied', $command, ['code' => 'AccessDenied']));
        $this->setUpMockedDisk($handler);

        $this->expectException(S3Exception::class);

        $this->artisan('documents:configure-bucket-lifecycle', ['disk' => 's3'])->run();
    }

    public function test_merges_and_preserves_foreign_rules(): void
    {
        $handler = new MockHandler;
        $captured = null;

        // Returns existing rules including a foreign rule and an old documentable rule
        $handler->append(new Result([
            'Rules' => [
                [
                    'ID' => 'foreign-app-rule',
                    'Status' => 'Enabled',
                    'Filter' => ['Prefix' => 'logs/'],
                    'Expiration' => ['Days' => 30],
                ],
                [
                    'ID' => 'documentable-abort-incomplete-multipart-uploads',
                    'Status' => 'Enabled',
                    'Filter' => ['Prefix' => ''],
                    'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 10],
                ],
            ],
        ]));

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result([]);
        });

        $this->setUpMockedDisk($handler);

        $this->artisan('documents:configure-bucket-lifecycle', ['disk' => 's3', '--days' => 5])
            ->assertExitCode(0);

        $rules = $captured['LifecycleConfiguration']['Rules'];
        $this->assertCount(3, $rules);

        $ids = array_column($rules, 'ID');
        $this->assertContains('foreign-app-rule', $ids);
        $this->assertContains('documentable-abort-incomplete-multipart-uploads', $ids);
        $this->assertContains('documentable-expire-doctor-scratch', $ids);

        $foreign = collect($rules)->firstWhere('ID', 'foreign-app-rule');
        $this->assertSame(30, $foreign['Expiration']['Days']);

        $abort = collect($rules)->firstWhere('ID', 'documentable-abort-incomplete-multipart-uploads');
        $this->assertSame(5, $abort['AbortIncompleteMultipartUpload']['DaysAfterInitiation']);
    }
}
