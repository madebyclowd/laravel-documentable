<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use MadeByClowd\Documentable\Exceptions\DiskDoesNotSupportTemporaryUrlsException;
use MadeByClowd\Documentable\Services\PresignClientFactory;
use MadeByClowd\Documentable\Services\Presigner;
use MadeByClowd\Documentable\Tests\TestCase;

class PresignerTest extends TestCase
{
    private function configureMockS3Disk(string $diskName = 's3_presign_disk'): S3Client
    {
        $s3Client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
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

        Storage::set($diskName, $disk);
        config()->set("filesystems.disks.{$diskName}", [
            'driver' => 's3',
            'bucket' => 'my-bucket',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'us-east-1',
        ]);

        return $s3Client;
    }

    public function test_download_presigns_get_object_with_disposition(): void
    {
        $this->configureMockS3Disk();
        $presigner = new Presigner(new PresignClientFactory);

        $url = $presigner->download('s3_presign_disk', 'files/doc.pdf', now()->addMinutes(10), 'attachment; filename="doc.pdf"');

        $this->assertStringContainsString('my-bucket', $url);
        $this->assertStringContainsString('files/doc.pdf', $url);
        // The SDK counts from its own clock read, so a second boundary between the two reads gives 599.
        $this->assertMatchesRegularExpression('/X-Amz-Expires=(599|600)&/', $url);
        $this->assertStringContainsString('response-content-disposition=attachment%3B%20filename%3D%22doc.pdf%22', $url);
        $this->assertStringNotContainsString('x-amz-checksum-', strtolower($url));
        $this->assertStringNotContainsString('x-amz-sdk-checksum-algorithm', strtolower($url));
    }

    public function test_upload_presigns_put_object_with_headers(): void
    {
        $this->configureMockS3Disk();
        $presigner = new Presigner(new PresignClientFactory);

        $result = $presigner->upload(
            's3_presign_disk',
            'files/upload.bin',
            now()->addMinutes(15),
            ['ServerSideEncryption' => 'AES256']
        );

        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('headers', $result);
        $this->assertStringContainsString('files/upload.bin', $result['url']);
        $this->assertSame('AES256', $result['headers']['x-amz-server-side-encryption']);
        $this->assertArrayNotHasKey('Host', $result['headers']);
    }

    public function test_part_presigns_upload_part(): void
    {
        $this->configureMockS3Disk();
        $presigner = new Presigner(new PresignClientFactory);

        $url = $presigner->part('s3_presign_disk', 'files/upload.bin', 'upload-abc', 2, now()->addMinutes(15));

        $this->assertStringContainsString('partNumber=2', $url);
        $this->assertStringContainsString('uploadId=upload-abc', $url);
        $this->assertStringNotContainsString('x-amz-checksum-', strtolower($url));
        $this->assertStringNotContainsString('x-amz-sdk-checksum-algorithm', strtolower($url));
    }

    public function test_non_s3_disk_download_falls_back_to_temporary_url(): void
    {
        config()->set('filesystems.disks.local_plain', [
            'driver' => 'local',
            'root' => sys_get_temp_dir(),
        ]);

        $presigner = new Presigner(new PresignClientFactory);

        $this->expectException(DiskDoesNotSupportTemporaryUrlsException::class);
        $presigner->download('local_plain', 'a.txt', now()->addMinutes(5), 'inline');
    }

    public function test_non_s3_disk_download_uses_custom_temporary_url_callback(): void
    {
        config()->set('filesystems.disks.local_custom', [
            'driver' => 'local',
            'root' => sys_get_temp_dir(),
        ]);

        Storage::disk('local_custom')->buildTemporaryUrlsUsing(fn ($path) => 'https://custom.local/'.$path);

        $presigner = new Presigner(new PresignClientFactory);
        $url = $presigner->download('local_custom', 'a.txt', now()->addMinutes(5), 'inline');

        $this->assertSame('https://custom.local/a.txt', $url);
    }
}
