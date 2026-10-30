<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Conformance;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Drivers\S3MultipartDriver;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\S3ClientDetector;

class S3MultipartDriverConformanceTest extends MultipartDriverConformanceTest
{
    private S3MultipartDriver $driver;

    /** @var list<string> */
    private array $createdPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $endpoint = env('RUSTFS_ENDPOINT');
        if (! is_string($endpoint) || $endpoint === '' || ! class_exists(S3Client::class)) {
            $this->markTestSkipped('RUSTFS_* is not configured.');
        }

        config(['filesystems.disks.rustfs_conformance' => [
            'driver' => 's3',
            'key' => env('RUSTFS_ACCESS_KEY_ID'),
            'secret' => env('RUSTFS_SECRET_ACCESS_KEY'),
            'region' => env('RUSTFS_REGION', 'us-east-1'),
            'bucket' => env('RUSTFS_BUCKET'),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]]);

        $this->driver = $this->app->make(S3MultipartDriver::class);
    }

    protected function tearDown(): void
    {
        if (config()->has('filesystems.disks.rustfs_conformance')) {
            $disk = Storage::disk('rustfs_conformance');
            foreach ($this->createdPaths as $path) {
                try {
                    $disk->delete($path);
                } catch (\Throwable) {
                    // Ignore deletion error in cleanup
                }
            }
        }

        parent::tearDown();
    }

    protected function driver(): MultipartUploadDriver
    {
        return $this->driver;
    }

    protected function createSession(string $path, string $filename): UploadSession
    {
        $this->createdPaths[] = $path;

        $created = $this->driver->create('rustfs_conformance', $path, $filename);

        $session = new UploadSession;
        $session->disk = 'rustfs_conformance';
        $session->path = $path;
        $session->upload_id = $created['upload_id'];

        return $session;
    }

    protected function uploadPart(UploadSession $session, int $partNumber, string $body): string
    {
        /** @var S3Client $client */
        $client = S3ClientDetector::client($session->disk);

        $result = $client->uploadPart([
            'Bucket' => (string) config("filesystems.disks.{$session->disk}.bucket"),
            'Key' => $session->path,
            'UploadId' => (string) $session->upload_id,
            'PartNumber' => $partNumber,
            'Body' => $body,
        ]);

        return (string) $result['ETag'];
    }

    protected function getStoredContents(UploadSession $session): string
    {
        return (string) Storage::disk($session->disk)->get($session->path);
    }
}
