<?php

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use MadeByClowd\Documentable\Drivers\S3MultipartDriver;
use MadeByClowd\Documentable\Tests\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * S3MultipartDriver is the real AWS-SDK-calling driver behind config('documentable.multipart.drivers.s3')
 * — every other test in the suite exercises MultipartUpload orchestration against
 * Tests\Fixtures\FakeMultipartUploadDriver instead, so this file is the only place the actual
 * S3Client parameter-building (bucket/key/SSE/checksum) gets covered. Aws\MockHandler intercepts
 * at the HTTP-handler layer — no real network call, no real credentials — so this exercises the
 * genuine AWS SDK call path without hitting AWS.
 */
class S3MultipartDriverTest extends TestCase
{
    protected function makeDriver(MockHandler $handler): S3MultipartDriver
    {
        $this->setUpDisk(['handler' => $handler]);

        return new S3MultipartDriver;
    }

    /**
     * Config alone (no client-swapping subclass) so client() -> Storage::disk($disk)->getClient()
     * runs for real and is exercised too — it just resolves a real S3Client wired to the
     * MockHandler via the disk config's 'handler' key, which Laravel's S3 driver passes straight
     * through to the S3Client constructor.
     */
    protected function setUpDisk(array $overrides = []): void
    {
        config()->set('filesystems.disks.s3_driver_test', array_merge([
            'driver' => 's3',
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'test-key',
            'secret' => 'test-secret',
        ], $overrides));
    }

    public function test_create_builds_multipart_upload_with_bucket_key_and_metadata(): void
    {
        $this->setUpDisk();
        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command, RequestInterface $request) use (&$captured) {
            $captured = $command;

            return new Result(['UploadId' => 'upload-123']);
        });

        $driver = $this->makeDriver($handler);

        $result = $driver->create('s3_driver_test', 'uploads/a.bin', 'a.bin');

        $this->assertSame(['upload_id' => 'upload-123'], $result);
        $this->assertSame('test-bucket', $captured['Bucket']);
        $this->assertSame('uploads/a.bin', $captured['Key']);
        $this->assertSame('a.bin', $captured['Metadata']['original_filename']);
        $this->assertArrayNotHasKey('ChecksumAlgorithm', $captured->toArray());
    }

    public function test_create_adds_native_checksum_algorithm_when_configured(): void
    {
        $this->setUpDisk();
        config()->set('documentable.multipart.use_native_checksum', true);

        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result(['UploadId' => 'upload-checksum']);
        });

        $driver = $this->makeDriver($handler);
        $driver->create('s3_driver_test', 'uploads/a.bin', 'a.bin');

        $this->assertSame('SHA256', $captured['ChecksumAlgorithm']);
    }

    public function test_create_applies_aes256_server_side_encryption(): void
    {
        $this->setUpDisk();
        config()->set('documentable.disks.s3_driver_test.server_side_encryption', 'AES256');

        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result(['UploadId' => 'upload-sse']);
        });

        $driver = $this->makeDriver($handler);
        $driver->create('s3_driver_test', 'uploads/a.bin', 'a.bin');

        $this->assertSame('AES256', $captured['ServerSideEncryption']);
        $this->assertArrayNotHasKey('SSEKMSKeyId', $captured->toArray());
    }

    public function test_create_applies_kms_server_side_encryption_with_key_id(): void
    {
        $this->setUpDisk();
        config()->set('documentable.disks.s3_driver_test.server_side_encryption', 'aws:kms');
        config()->set('documentable.disks.s3_driver_test.kms_key_id', 'arn:aws:kms:us-east-1:123:key/abc');

        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result(['UploadId' => 'upload-kms']);
        });

        $driver = $this->makeDriver($handler);
        $driver->create('s3_driver_test', 'uploads/a.bin', 'a.bin');

        $this->assertSame('aws:kms', $captured['ServerSideEncryption']);
        $this->assertSame('arn:aws:kms:us-east-1:123:key/abc', $captured['SSEKMSKeyId']);
    }

    public function test_presign_part_upload_returns_a_signed_url(): void
    {
        $this->setUpDisk();
        $driver = $this->makeDriver(new MockHandler);

        $url = $driver->presignPartUpload('s3_driver_test', 'uploads/a.bin', 'upload-123', 2);

        $this->assertStringContainsString('test-bucket', $url);
        $this->assertStringContainsString('uploads/a.bin', $url);
        $this->assertStringContainsString('partNumber=2', $url);
        $this->assertStringContainsString('uploadId=upload-123', $url);
    }

    public function test_list_parts_returns_flattened_part_number_and_etag(): void
    {
        $this->setUpDisk();
        $handler = new MockHandler;
        $handler->append(new Result([
            'Parts' => [
                ['PartNumber' => 1, 'ETag' => '"etag-1"'],
                ['PartNumber' => 2, 'ETag' => '"etag-2"'],
            ],
            'IsTruncated' => false,
        ]));

        $driver = $this->makeDriver($handler);
        $parts = $driver->listParts('s3_driver_test', 'uploads/a.bin', 'upload-123');

        $this->assertSame([
            ['PartNumber' => 1, 'ETag' => '"etag-1"'],
            ['PartNumber' => 2, 'ETag' => '"etag-2"'],
        ], $parts);
    }

    public function test_list_parts_follows_pagination_until_not_truncated(): void
    {
        $this->setUpDisk();
        $handler = new MockHandler;
        $handler->append(
            new Result([
                'Parts' => [['PartNumber' => 1, 'ETag' => '"etag-1"']],
                'IsTruncated' => true,
                'NextPartNumberMarker' => '1',
            ]),
            new Result([
                'Parts' => [['PartNumber' => 2, 'ETag' => '"etag-2"']],
                'IsTruncated' => false,
            ])
        );

        $driver = $this->makeDriver($handler);
        $parts = $driver->listParts('s3_driver_test', 'uploads/a.bin', 'upload-123');

        $this->assertSame([1, 2], array_column($parts, 'PartNumber'));
        $this->assertSame(0, count($handler));
    }

    public function test_complete_calls_complete_multipart_upload_with_parts(): void
    {
        $this->setUpDisk();
        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result([]);
        });

        $driver = $this->makeDriver($handler);
        $driver->complete('s3_driver_test', 'uploads/a.bin', 'upload-123', [
            ['PartNumber' => 1, 'ETag' => '"etag-1"'],
        ]);

        $this->assertSame('test-bucket', $captured['Bucket']);
        $this->assertSame('upload-123', $captured['UploadId']);
        $this->assertSame([['PartNumber' => 1, 'ETag' => '"etag-1"']], $captured['MultipartUpload']['Parts']);
    }

    public function test_abort_calls_abort_multipart_upload(): void
    {
        $this->setUpDisk();
        $handler = new MockHandler;
        $captured = null;

        $handler->append(function (CommandInterface $command) use (&$captured) {
            $captured = $command;

            return new Result([]);
        });

        $driver = $this->makeDriver($handler);
        $driver->abort('s3_driver_test', 'uploads/a.bin', 'upload-123');

        $this->assertSame('test-bucket', $captured['Bucket']);
        $this->assertSame('uploads/a.bin', $captured['Key']);
        $this->assertSame('upload-123', $captured['UploadId']);
    }

    public function test_retrieve_checksum_decodes_base64_sha256_to_hex(): void
    {
        $this->setUpDisk();
        $rawChecksum = hash('sha256', 'hello world', true);

        $handler = new MockHandler;
        $handler->append(new Result([
            'ChecksumSHA256' => base64_encode($rawChecksum),
        ]));

        $driver = $this->makeDriver($handler);
        $checksum = $driver->retrieveChecksum('s3_driver_test', 'uploads/a.bin');

        $this->assertSame(bin2hex($rawChecksum), $checksum);
    }

    public function test_retrieve_checksum_returns_null_when_provider_has_none(): void
    {
        $this->setUpDisk();
        $handler = new MockHandler;
        $handler->append(new Result([]));

        $driver = $this->makeDriver($handler);
        $checksum = $driver->retrieveChecksum('s3_driver_test', 'uploads/a.bin');

        $this->assertNull($checksum);
    }
}
