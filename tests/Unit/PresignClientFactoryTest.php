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

    private function presignDisk(array $diskConfig, array $documentable = []): S3Client
    {
        config()->set('filesystems.disks.presign_probe', ['driver' => 's3', 'bucket' => 'my-bucket', 'key' => 'k', 'secret' => 's'] + $diskConfig);
        config()->set('documentable.disks.presign_probe', ['presign_endpoint' => 'https://public.example.com'] + $documentable);

        return (new PresignClientFactory)->forDisk('presign_probe');
    }

    public function test_it_forwards_a_session_token_only_when_the_disk_has_one(): void
    {
        $withToken = $this->presignDisk(['token' => 'session-token']);
        $withoutToken = $this->presignDisk([]);

        $this->assertSame('session-token', $withToken->getCredentials()->wait()->getSecurityToken());
        $this->assertNull($withoutToken->getCredentials()->wait()->getSecurityToken());
    }

    public function test_it_defaults_the_region_when_the_disk_has_none(): void
    {
        $this->assertSame('us-east-1', $this->presignDisk([])->getRegion());
        $this->assertSame('eu-west-1', $this->presignDisk(['region' => 'eu-west-1'])->getRegion());
    }

    public function test_it_refuses_a_disk_that_has_no_s3_client(): void
    {
        config()->set('filesystems.disks.plain_local', ['driver' => 'local', 'root' => sys_get_temp_dir()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Disk [plain_local] does not provide an S3 client.');

        (new PresignClientFactory)->forDisk('plain_local');
    }

    public function test_it_signs_virtual_hosted_style_unless_a_path_style_setting_says_otherwise(): void
    {
        $client = $this->presignDisk([]);

        $url = (string) $client->createPresignedRequest($client->getCommand('GetObject', ['Bucket' => 'my-bucket', 'Key' => 'k']), '+5 minutes')->getUri();

        $this->assertStringStartsWith('https://my-bucket.public.example.com/', $url);
    }

    public function test_it_takes_credentials_from_an_explicit_credentials_array_on_the_disk(): void
    {
        config()->set('filesystems.disks.presign_probe', [
            'driver' => 's3',
            'bucket' => 'my-bucket',
            'credentials' => ['key' => 'array-key', 'secret' => 'array-secret'],
        ]);
        config()->set('documentable.disks.presign_probe.presign_endpoint', 'https://public.example.com');

        $credentials = (new PresignClientFactory)->forDisk('presign_probe')->getCredentials()->wait();

        $this->assertSame('array-key', $credentials->getAccessKeyId());
        $this->assertSame('array-secret', $credentials->getSecretKey());
    }

    public function test_presign_use_path_style_overrides_the_disks_own_setting_in_both_directions(): void
    {
        $disk = ['use_path_style_endpoint' => true];

        $overriddenOff = $this->presignDisk($disk, ['presign_use_path_style' => false]);
        $overriddenOn = $this->presignDisk(['use_path_style_endpoint' => false], ['presign_use_path_style' => true]);
        $inherited = $this->presignDisk($disk);

        $url = fn (S3Client $client) => (string) $client->createPresignedRequest(
            $client->getCommand('GetObject', ['Bucket' => 'my-bucket', 'Key' => 'k']), '+5 minutes'
        )->getUri();

        $this->assertStringStartsWith('https://my-bucket.public.example.com/', $url($overriddenOff));
        $this->assertStringStartsWith('https://public.example.com/my-bucket/', $url($overriddenOn));
        $this->assertStringStartsWith('https://public.example.com/my-bucket/', $url($inherited));
    }
}
