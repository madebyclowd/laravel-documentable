<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Doctor;

use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;
use MadeByClowd\Documentable\Doctor\DoctorContext;
use MadeByClowd\Documentable\Doctor\DoctorRunner;
use MadeByClowd\Documentable\Doctor\Probe;
use MadeByClowd\Documentable\Doctor\ProbeResult;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;

class DocumentsDoctorCommandTest extends TestCase
{
    protected function setUpDisk(string $disk = 's3_doctor_test', array $overrides = []): void
    {
        config()->set("filesystems.disks.{$disk}", array_merge([
            'driver' => 's3',
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'request_checksum_calculation' => 'when_required',
        ], $overrides));
    }

    public function test_doctor_command_fails_if_disk_is_not_s3(): void
    {
        config()->set('filesystems.disks.local_disk', ['driver' => 'local', 'root' => storage_path()]);

        $this->artisan('documents:doctor', ['disk' => 'local_disk'])
            ->expectsOutputToContain('Disk [local_disk] is not an S3 disk.')
            ->assertExitCode(1);
    }

    /**
     * Answers every command with an empty result except PutObject, which $putBehavior decides, so a probe's own
     * request is the only thing under test.
     */
    private function mockPutObject(callable $putBehavior): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_doctor_test', ['handler' => $mock]);
        Http::fake(['*' => Http::response('', 200, ['ETag' => '"etag"'])]);

        for ($i = 0; $i < 40; $i++) {
            $mock->append(fn (CommandInterface $cmd) => $cmd->getName() === 'PutObject' ? $putBehavior($cmd) : new Result);
        }
    }

    private static function rejectsDefaultChecksums(CommandInterface $cmd): Result
    {
        $mode = $cmd['@context']['request_checksum_calculation'] ?? 'when_required';

        return $mode === 'when_supported'
            ? throw new S3Exception('x-amz-checksum-crc32 is not supported', $cmd, ['response' => new Response(400), 'code' => 'InvalidRequest'])
            : new Result;
    }

    public function test_doctor_fails_checksum_probe_when_only_the_default_sdk_checksums_are_rejected(): void
    {
        $this->mockPutObject(self::rejectsDefaultChecksums(...));

        $this->artisan('documents:doctor', ['disk' => 's3_doctor_test'])
            ->expectsOutputToContain('[FAIL] ChecksumHeaders')
            ->expectsOutputToContain("'request_checksum_calculation' => 'when_required'")
            ->assertExitCode(1);
    }

    public function test_doctor_passes_checksum_probe_when_the_provider_accepts_default_sdk_checksums(): void
    {
        $this->mockPutObject(fn () => new Result);

        $this->artisan('documents:doctor', ['disk' => 's3_doctor_test'])
            ->expectsOutputToContain('[PASS] ChecksumHeaders')
            ->run();
    }

    public function test_doctor_skips_checksum_probe_when_puts_fail_either_way(): void
    {
        $this->mockPutObject(fn (CommandInterface $cmd) => throw new S3Exception('denied', $cmd, ['response' => new Response(403), 'code' => 'AccessDenied']));

        $this->artisan('documents:doctor', ['disk' => 's3_doctor_test'])
            ->expectsOutputToContain('[SKIPPED] ChecksumHeaders')
            ->run();
    }

    public function test_doctor_command_reports_metadata_failure_with_fix(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_doctor_test', ['handler' => $mock]);

        // PutObjectProbe
        $mock->append(new Result);
        // PresignedPutProbe
        Http::fake(['*' => Http::response('', 200)]);
        // MultipartCreateWithMetadataProbe throws S3Exception
        $cmd = new Command('CreateMultipartUpload', ['Bucket' => 'test-bucket', 'Key' => 'mp.bin']);
        $mock->append(new S3Exception('Metadata header blocked by proxy', $cmd));

        $this->artisan('documents:doctor', ['disk' => 's3_doctor_test'])
            ->expectsOutputToContain('[FAIL] MultipartCreateWithMetadata')
            ->expectsOutputToContain('Disable object metadata in config (DOCUMENTABLE_MULTIPART_OBJECT_METADATA=false) or configure your proxy to forward metadata headers.')
            ->assertExitCode(1);
    }

    private function appendMockProbes(MockHandler $mock, mixed $lifecycleResponse = null): void
    {
        $callback = function (CommandInterface $cmd) use ($lifecycleResponse) {
            $name = $cmd->getName();

            if ($name === 'CreateMultipartUpload') {
                return new Result(['UploadId' => 'mp-123']);
            }
            if ($name === 'ListParts') {
                return new Result(['Parts' => [
                    ['PartNumber' => 1, 'ETag' => '"etag-123"'],
                    ['PartNumber' => 2, 'ETag' => '"etag-456"'],
                ]]);
            }
            if ($name === 'CompleteMultipartUpload') {
                return new Result(['ETag' => '"etag-comp"']);
            }
            if ($name === 'GetObject') {
                return new Result(['Body' => '0123']);
            }
            if ($name === 'HeadObject') {
                $res404 = new Response(404);
                throw new S3Exception('404 Not Found', $cmd, ['response' => $res404, 'code' => 'NotFound']);
            }
            if ($name === 'GetBucketLifecycleConfiguration') {
                if ($lifecycleResponse instanceof \Throwable) {
                    throw $lifecycleResponse;
                }

                return $lifecycleResponse ?? new Result(['Rules' => []]);
            }
            if ($name === 'ListMultipartUploads') {
                return new Result;
            }
            if ($name === 'ListObjectsV2') {
                return new Result;
            }

            return new Result;
        };

        for ($i = 0; $i < 40; $i++) {
            $mock->append($callback);
        }
    }

    public function test_doctor_command_reports_lifecycle_failure_with_fix(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_doctor_test', ['handler' => $mock]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response('doctor-presigned-get-payload', 200);
            }

            return Http::response('', 200, ['ETag' => '"etag-123"']);
        });

        $this->appendMockProbes($mock, new Result(['Rules' => []]));

        $this->artisan('documents:doctor', ['disk' => 's3_doctor_test'])
            ->expectsOutputToContain('[FAIL] LifecycleRules')
            ->expectsOutputToContain('Run php artisan documents:configure-bucket-lifecycle s3_doctor_test to configure lifecycle rules.')
            ->assertExitCode(1);
    }

    private function runDoctorWithLifecycleRules(array $rules): PendingCommand
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_doctor_test', ['handler' => $mock]);
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response('doctor-presigned-get-payload', 200)
            : Http::response('', 200, ['ETag' => '"etag-123"']));
        $this->appendMockProbes($mock, new Result(['Rules' => $rules]));

        return $this->artisan('documents:doctor', ['disk' => 's3_doctor_test']);
    }

    public function test_doctor_fails_lifecycle_probe_when_the_multipart_abort_rule_is_missing(): void
    {
        $this->runDoctorWithLifecycleRules([
            ['ID' => 'scratch', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'documentable-doctor/'], 'Expiration' => ['Days' => 1]],
        ])
            ->expectsOutputToContain('[FAIL] LifecycleRules')
            ->expectsOutputToContain('documents:configure-bucket-lifecycle s3_doctor_test')
            ->assertExitCode(1);
    }

    private const SCRATCH_EXPIRY = ['ID' => 'scratch', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'documentable-doctor/'], 'Expiration' => ['Days' => 1]];

    private const BUCKET_WIDE_ABORT = ['ID' => 'abort', 'Status' => 'Enabled', 'Filter' => ['Prefix' => ''], 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 3]];

    public function test_lifecycle_probe_names_exactly_what_is_missing(): void
    {
        $this->runDoctorWithLifecycleRules([])
            ->expectsOutputToContain('The bucket has no lifecycle rule to: abort incomplete multipart uploads and expire the doctor scratch prefix.')
            ->assertExitCode(1);
    }

    public function test_lifecycle_probe_ignores_disabled_rules(): void
    {
        $this->runDoctorWithLifecycleRules([
            ['Status' => 'Disabled'] + self::SCRATCH_EXPIRY,
            ['Status' => 'Disabled'] + self::BUCKET_WIDE_ABORT,
        ])
            ->expectsOutputToContain('[FAIL] LifecycleRules')
            ->assertExitCode(1);
    }

    public function test_lifecycle_probe_requires_the_abort_rule_to_cover_the_whole_bucket(): void
    {
        $this->runDoctorWithLifecycleRules([
            self::SCRATCH_EXPIRY,
            ['Filter' => ['Prefix' => 'tmp/']] + self::BUCKET_WIDE_ABORT,
        ])
            ->expectsOutputToContain('no lifecycle rule to: abort incomplete multipart uploads.')
            ->assertExitCode(1);
    }

    public function test_lifecycle_probe_requires_the_expiry_rule_to_cover_the_scratch_prefix(): void
    {
        $this->runDoctorWithLifecycleRules([
            self::BUCKET_WIDE_ABORT,
            ['Filter' => ['Prefix' => 'unrelated/']] + self::SCRATCH_EXPIRY,
        ])
            ->expectsOutputToContain('no lifecycle rule to: expire the doctor scratch prefix.')
            ->assertExitCode(1);
    }

    public function test_lifecycle_probe_does_not_accept_a_rule_of_the_wrong_kind(): void
    {
        // A bucket-wide expiration is not an abort-incomplete-multipart rule.
        $this->runDoctorWithLifecycleRules([
            ['ID' => 'expire-all', 'Status' => 'Enabled', 'Filter' => ['Prefix' => ''], 'Expiration' => ['Days' => 30]],
        ])
            ->expectsOutputToContain('no lifecycle rule to: abort incomplete multipart uploads.')
            ->assertExitCode(1);
    }

    public function test_lifecycle_probe_reads_the_legacy_and_the_and_filter_prefix_forms(): void
    {
        $this->runDoctorWithLifecycleRules([
            ['ID' => 'legacy-abort', 'Status' => 'Enabled', 'Prefix' => '', 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 3]],
            ['ID' => 'and-expiry', 'Status' => 'Enabled', 'Filter' => ['And' => ['Prefix' => 'documentable-doctor/']], 'Expiration' => ['Days' => 1]],
        ])
            ->expectsOutputToContain('[PASS] LifecycleRules')
            ->assertExitCode(0);
    }

    public function test_doctor_passes_lifecycle_probe_with_both_rules_present(): void
    {
        $this->runDoctorWithLifecycleRules([
            ['ID' => 'scratch', 'Status' => 'Enabled', 'Filter' => ['Prefix' => 'documentable-doctor/'], 'Expiration' => ['Days' => 1]],
            ['ID' => 'abort', 'Status' => 'Enabled', 'Filter' => ['Prefix' => ''], 'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => 3]],
        ])
            ->expectsOutputToContain('[PASS] LifecycleRules')
            ->assertExitCode(0);
    }

    public function test_doctor_command_skips_lifecycle_when_access_denied(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_doctor_test', ['handler' => $mock]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response('doctor-presigned-get-payload', 200);
            }

            return Http::response('', 200, ['ETag' => '"etag-123"']);
        });

        $lifecycleCmd = new Command('GetBucketLifecycleConfiguration', ['Bucket' => 'test-bucket']);
        $this->appendMockProbes($mock, new S3Exception('AccessDenied', $lifecycleCmd, ['code' => 'AccessDenied']));

        $this->artisan('documents:doctor', ['disk' => 's3_doctor_test'])
            ->expectsOutputToContain("[SKIPPED] LifecycleRules: Cannot read the bucket's lifecycle settings with this token (object read and write only?).")
            ->expectsOutputToContain('Check them in the provider dashboard.')
            ->assertExitCode(0);
    }

    public function test_doctor_runner_guarantees_cleanup_even_if_probe_throws(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_doctor_test', ['handler' => $mock]);

        // Probe 1: track a multipart upload then throw
        $failingProbe = new class implements Probe
        {
            public function name(): string
            {
                return 'FailingProbe';
            }

            public function run(DoctorContext $context): ProbeResult
            {
                $context->trackMultipart('open-mp-id', $context->scratchPrefix.'test.bin');
                throw new RuntimeException('Probe failed unexpectedly');
            }
        };

        // Cleanup expectations:
        // 1. abortMultipartUpload for tracked multipart
        $abortedKey = null;
        $mock->append(function ($cmd) use (&$abortedKey) {
            $abortedKey = $cmd['Key'];

            return new Result;
        });
        // 2. listMultipartUploads
        $mock->append(new Result(['Uploads' => []]));
        // 3. listObjectsV2
        $mock->append(new Result(['Contents' => [['Key' => 'scratch/leftover.txt']]]));
        // 4. deleteObjects
        $deletedKeys = null;
        $mock->append(function ($cmd) use (&$deletedKeys) {
            $deletedKeys = $cmd['Delete']['Objects'];

            return new Result;
        });

        $runner = $this->app->makeWith(DoctorRunner::class, ['probes' => [$failingProbe]]);

        try {
            $runner->run('s3_doctor_test');
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Probe failed unexpectedly', $e->getMessage());
        }

        $this->assertNotNull($abortedKey);
        $this->assertNotNull($deletedKeys);
        $this->assertSame('scratch/leftover.txt', $deletedKeys[0]['Key']);
    }

    public function test_doctor_runner_warns_if_cleanup_fails(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_doctor_test', ['handler' => $mock]);

        $dummyProbe = new class implements Probe
        {
            public function name(): string
            {
                return 'Dummy';
            }

            public function run(DoctorContext $context): ProbeResult
            {
                return ProbeResult::pass($this->name(), 'Passed');
            }
        };

        // 1. listMultipartUploads
        $mock->append(new Result(['Uploads' => []]));
        // 2. listObjectsV2 throws S3Exception
        $cmd = new Command('ListObjectsV2', ['Bucket' => 'test-bucket']);
        $mock->append(new S3Exception('Network error during listObjectsV2', $cmd));

        Log::shouldReceive('warning')->once()->withArgs(function (string $message) {
            return str_contains($message, 'Doctor cleanup could not delete scratch objects');
        });

        $runner = $this->app->makeWith(DoctorRunner::class, ['probes' => [$dummyProbe]]);
        $warnedMessage = null;
        $results = $runner->run('s3_doctor_test', function (string $msg) use (&$warnedMessage) {
            $warnedMessage = $msg;
        });

        $this->assertCount(1, $results);
        $this->assertNotNull($warnedMessage);
        $this->assertStringContainsString('Doctor cleanup could not delete scratch objects', $warnedMessage);
    }

    public function test_doctor_runs_against_real_rustfs(): void
    {
        $endpoint = env('RUSTFS_ENDPOINT');
        if (! is_string($endpoint) || $endpoint === '' || ! class_exists(S3Client::class)) {
            $this->markTestSkipped('RUSTFS_* is not configured.');
        }

        $bucket = (string) env('RUSTFS_BUCKET', 'documentable-test');

        config(['filesystems.disks.rustfs_doctor' => [
            'driver' => 's3',
            'key' => env('RUSTFS_ACCESS_KEY_ID'),
            'secret' => env('RUSTFS_SECRET_ACCESS_KEY'),
            'region' => env('RUSTFS_REGION', 'us-east-1'),
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'throw' => true,
        ]]);

        // First configure lifecycle rules on RustFS bucket so LifecycleRulesProbe passes
        $this->artisan('documents:configure-bucket-lifecycle', [
            'disk' => 'rustfs_doctor',
        ])->assertExitCode(0);

        $this->artisan('documents:doctor', ['disk' => 'rustfs_doctor'])
            ->expectsOutputToContain('[PASS] PutObject')
            ->expectsOutputToContain('[PASS] PresignedPut')
            ->expectsOutputToContain('[PASS] MultipartCreateWithMetadata')
            ->expectsOutputToContain('[PASS] MultipartCreateWithoutMetadata')
            ->expectsOutputToContain('[PASS] UploadPart')
            ->expectsOutputToContain('[PASS] ExposedEtagHeader')
            ->expectsOutputToContain('[PASS] RangedGet')
            ->expectsOutputToContain('[PASS] PresignedGet')
            ->expectsOutputToContain('[PASS] DeleteAndExists')
            ->expectsOutputToContain('All diagnostics passed')
            ->assertExitCode(0);

        // Verify scratch prefix is completely clean
        $scratchBase = (string) config('documentable.doctor.scratch_prefix', 'documentable-doctor');
        $s3 = Storage::disk('rustfs_doctor');
        $files = $s3->allFiles($scratchBase);
        $this->assertEmpty($files, 'Scratch prefix was not cleaned up after doctor run.');
    }
}
