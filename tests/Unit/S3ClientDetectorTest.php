<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use MadeByClowd\Documentable\Services\S3ClientDetector;
use MadeByClowd\Documentable\Tests\TestCase;

class S3ClientDetectorTest extends TestCase
{
    public function test_it_detects_non_s3_disks(): void
    {
        config()->set('filesystems.disks.local_disk', ['driver' => 'local', 'root' => sys_get_temp_dir()]);

        $this->assertFalse(S3ClientDetector::isS3('local_disk'));
        $this->assertNull(S3ClientDetector::client('local_disk'));
    }

    public function test_it_detects_s3_client_when_present(): void
    {
        $s3Client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $driver = new LocalFilesystemAdapter(sys_get_temp_dir());
        $disk = new class(new Filesystem($driver), $driver, [], $s3Client) extends FilesystemAdapter
        {
            public function __construct($driver, $adapter, array $config, private readonly S3Client $client)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function getClient(): S3Client
            {
                return $this->client;
            }
        };

        Storage::set('mock_s3', $disk);

        $this->assertTrue(S3ClientDetector::isS3('mock_s3'));
        $this->assertSame($s3Client, S3ClientDetector::client('mock_s3'));
    }

    public function test_a_disk_with_no_configured_driver_is_not_s3(): void
    {
        $this->assertNull(S3ClientDetector::client('no_such_disk'));
        $this->assertFalse(S3ClientDetector::isS3('no_such_disk'));
    }

    public function test_an_unexpected_failure_building_the_client_surfaces_instead_of_reading_as_not_s3(): void
    {
        $driver = new LocalFilesystemAdapter(sys_get_temp_dir());
        Storage::set('broken_s3', new class(new Filesystem($driver), $driver, []) extends FilesystemAdapter
        {
            public function getClient(): S3Client
            {
                throw new \RuntimeException('credentials provider crashed');
            }
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('credentials provider crashed');

        S3ClientDetector::client('broken_s3');
    }
}
