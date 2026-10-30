<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use MadeByClowd\Documentable\Services\PresignClientFactory;
use MadeByClowd\Documentable\Tests\TestCase;

class PresignClientFactoryTest extends TestCase
{
    public function test_it_uses_disks_own_get_client_when_presign_endpoint_is_not_set(): void
    {
        $internalClient = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
        ]);

        $driver = new LocalFilesystemAdapter(sys_get_temp_dir());
        $disk = new class(new Filesystem($driver), $driver, [], $internalClient) extends FilesystemAdapter
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

        Storage::set('custom_s3', $disk);
        config()->set('filesystems.disks.custom_s3', [
            'driver' => 's3',
            'bucket' => 'bucket-1',
            'key' => 'k',
            'secret' => 's',
            'region' => 'us-east-1',
        ]);

        $factory = new PresignClientFactory;
        $client1 = $factory->forDisk('custom_s3');
        $client2 = $factory->forDisk('custom_s3');

        $this->assertSame($internalClient, $client1);
        $this->assertSame($client1, $client2);
    }

    public function test_it_builds_separate_s3_client_when_presign_endpoint_is_configured(): void
    {
        config()->set('filesystems.disks.s3_with_presign', [
            'driver' => 's3',
            'bucket' => 'bucket-presign',
            'key' => 'key-abc',
            'secret' => 'secret-xyz',
            'token' => 'session-token',
            'region' => 'eu-west-1',
            'version' => 'latest',
            'use_path_style_endpoint' => false,
        ]);
        config()->set('documentable.disks.s3_with_presign.presign_endpoint', 'https://public.example.com');
        config()->set('documentable.disks.s3_with_presign.presign_use_path_style', true);

        $factory = new PresignClientFactory;
        $client1 = $factory->forDisk('s3_with_presign');
        $client2 = $factory->forDisk('s3_with_presign');

        $this->assertInstanceOf(S3Client::class, $client1);
        $this->assertSame($client1, $client2);
        $this->assertSame('https://public.example.com', (string) $client1->getEndpoint());
        $this->assertSame('eu-west-1', $client1->getRegion());

        $cmd = $client1->getCommand('GetObject', ['Bucket' => 'bucket-presign', 'Key' => 'test.txt']);
        $url = (string) $client1->createPresignedRequest($cmd, '+10 minutes')->getUri();

        $this->assertStringStartsWith('https://public.example.com/bucket-presign/test.txt', $url);
    }
}
