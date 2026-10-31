<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Provider;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Services\Presigner;
use MadeByClowd\Documentable\Tests\TestCase;

class PresignRoundTripTest extends TestCase
{
    private Presigner $presigner;

    /** @var list<string> */
    private array $createdPaths = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->presigner = $this->app->make(Presigner::class);
    }

    protected function tearDown(): void
    {
        if (config()->has('filesystems.disks.rustfs_presign_test')) {
            $disk = Storage::disk('rustfs_presign_test');
            foreach ($this->createdPaths as $path) {
                try {
                    $disk->delete($path);
                } catch (\Throwable) {
                    // Ignore cleanup error
                }
            }
        }

        parent::tearDown();
    }

    private function requireRustFs(): void
    {
        $endpoint = env('RUSTFS_ENDPOINT');
        if (! is_string($endpoint) || $endpoint === '' || ! class_exists(S3Client::class)) {
            $this->markTestSkipped('RUSTFS_* is not configured.');
        }

        $bucket = (string) env('RUSTFS_BUCKET', 'documentable-test');

        // Internal endpoint: 127.0.0.1:9000
        config(['filesystems.disks.rustfs_presign_test' => [
            'driver' => 's3',
            'key' => env('RUSTFS_ACCESS_KEY_ID'),
            'secret' => env('RUSTFS_SECRET_ACCESS_KEY'),
            'region' => env('RUSTFS_REGION', 'us-east-1'),
            'bucket' => $bucket,
            'endpoint' => 'http://127.0.0.1:9000',
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]]);

        // Public presign endpoint: localhost:9000
        config(['documentable.disks.rustfs_presign_test.presign_endpoint' => 'http://localhost:9000']);
        config(['documentable.disks.rustfs_presign_test.presign_use_path_style' => true]);
    }

    public function test_presign_urls_work_when_internal_endpoint_differs_from_public_endpoint(): void
    {
        $this->requireRustFs();

        $path = 'round-trip/host-swap-'.bin2hex(random_bytes(6)).'.txt';
        $this->createdPaths[] = $path;
        $payload = 'hello world round-trip content';

        // 1. Presign upload (PUT)
        $upload = $this->presigner->upload(
            'rustfs_presign_test',
            $path,
            now()->addMinutes(10),
        );

        $uploadUrl = $upload['url'];
        $uploadParts = parse_url($uploadUrl);

        $this->assertSame('localhost', $uploadParts['host'] ?? null);
        $this->assertSame(9000, $uploadParts['port'] ?? null);

        // Upload using the presigned URL directed to localhost:9000
        $putResponse = Http::withBody($payload, 'text/plain')->put($uploadUrl);
        $this->assertTrue($putResponse->successful(), "Presigned PUT to localhost:9000 failed: {$putResponse->status()} {$putResponse->body()}");

        // 2. Presign download (GET)
        $downloadUrl = $this->presigner->download(
            'rustfs_presign_test',
            $path,
            now()->addMinutes(10),
            'inline; filename="downloaded.txt"'
        );

        $downloadParts = parse_url($downloadUrl);
        $this->assertSame('localhost', $downloadParts['host'] ?? null);
        $this->assertSame(9000, $downloadParts['port'] ?? null);

        // Download using the presigned URL directed to localhost:9000
        $getResponse = Http::get($downloadUrl);
        $this->assertTrue($getResponse->successful(), "Presigned GET from localhost:9000 failed: {$getResponse->status()} {$getResponse->body()}");
        $this->assertSame($payload, $getResponse->body());
    }

    public function test_presigned_query_parameters_match_provider_requirements(): void
    {
        $this->requireRustFs();

        $path = 'round-trip/params-check.txt';

        // 1. Check download presign query params
        $downloadUrl = $this->presigner->download(
            'rustfs_presign_test',
            $path,
            now()->addMinutes(15),
            'attachment; filename="params.txt"'
        );

        $queryStr = parse_url($downloadUrl, PHP_URL_QUERY) ?? '';
        parse_str($queryStr, $params);

        $this->assertArrayHasKey('X-Amz-Expires', $params);
        $expires = (int) $params['X-Amz-Expires'];
        $this->assertGreaterThanOrEqual(890, $expires);
        $this->assertLessThanOrEqual(900, $expires);

        $this->assertArrayHasKey('X-Amz-SignedHeaders', $params);
        $signedHeaders = explode(';', (string) $params['X-Amz-SignedHeaders']);
        $this->assertContains('host', $signedHeaders);

        // No x-amz-checksum-* headers signed by default
        foreach ($signedHeaders as $header) {
            $this->assertStringStartsNotWith('x-amz-checksum-', strtolower($header));
        }

        // No x-amz-sdk-checksum-algorithm query parameter
        $this->assertArrayNotHasKey('x-amz-sdk-checksum-algorithm', $params);

        // response-content-disposition query parameter is present for downloads
        $this->assertArrayHasKey('response-content-disposition', $params);
        $this->assertSame('attachment; filename="params.txt"', $params['response-content-disposition']);

        // 2. Check upload presign query params
        $upload = $this->presigner->upload(
            'rustfs_presign_test',
            $path,
            now()->addMinutes(15),
        );

        $uploadQueryStr = parse_url($upload['url'], PHP_URL_QUERY) ?? '';
        parse_str($uploadQueryStr, $uploadParams);

        $this->assertArrayHasKey('X-Amz-Expires', $uploadParams);
        $this->assertArrayHasKey('X-Amz-SignedHeaders', $uploadParams);
        $this->assertArrayNotHasKey('x-amz-sdk-checksum-algorithm', $uploadParams);
    }
}
