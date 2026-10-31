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

        // RustFS by default; S3_CONFORMANCE_PROVIDER=R2 runs the same suite against a real Cloudflare R2 bucket.
        $provider = strtoupper((string) env('S3_CONFORMANCE_PROVIDER', 'RUSTFS'));
        $endpoint = env($provider.'_ENDPOINT');
        if (! is_string($endpoint) || $endpoint === '' || ! class_exists(S3Client::class)) {
            $this->markTestSkipped("{$provider}_* is not configured.");
        }

        config(['filesystems.disks.s3_conformance' => [
            'driver' => 's3',
            'key' => env($provider.'_ACCESS_KEY_ID'),
            'secret' => env($provider.'_SECRET_ACCESS_KEY'),
            'region' => env($provider.'_REGION', 'us-east-1'),
            'bucket' => env($provider.'_BUCKET'),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => filter_var(env($provider.'_USE_PATH_STYLE', $provider === 'RUSTFS'), FILTER_VALIDATE_BOOLEAN),
            'throw' => true,
        ]]);

        $this->driver = $this->app->make(S3MultipartDriver::class);
    }

    protected function tearDown(): void
    {
        if (config()->has('filesystems.disks.s3_conformance')) {
            $disk = Storage::disk('s3_conformance');
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

        $created = $this->driver->create('s3_conformance', $path, $filename);

        $session = new UploadSession;
        $session->disk = 's3_conformance';
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
