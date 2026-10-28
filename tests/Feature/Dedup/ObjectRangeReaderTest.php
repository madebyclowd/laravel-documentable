<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Dedup;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Services\ObjectRangeReader;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;
use Throwable;

class ObjectRangeReaderTest extends TestCase
{
    public function test_it_reads_a_range_from_a_fake_disk(): void
    {
        Storage::fake('test_disk');
        Storage::disk('test_disk')->put('a.bin', '0123456789');

        $reader = new ObjectRangeReader;

        $this->assertSame('3456', $reader->read('test_disk', 'a.bin', 3, 4));
        $this->assertSame('0', $reader->read('test_disk', 'a.bin', 0, 1));
        $this->assertSame('9', $reader->read('test_disk', 'a.bin', 9, 1));
    }

    public function test_it_fails_for_a_range_past_the_end(): void
    {
        Storage::fake('test_disk');
        Storage::disk('test_disk')->put('a.bin', '0123456789');

        $this->expectException(RuntimeException::class);
        (new ObjectRangeReader)->read('test_disk', 'a.bin', 8, 5);
    }

    public function test_it_fails_for_a_missing_object(): void
    {
        Storage::fake('test_disk');

        $this->expectException(RuntimeException::class);
        (new ObjectRangeReader)->read('test_disk', 'missing.bin', 0, 1);
    }

    public function test_it_issues_a_ranged_get_on_a_real_s3_compatible_store(): void
    {
        $endpoint = env('RUSTFS_ENDPOINT');
        if (! is_string($endpoint) || $endpoint === '' || ! class_exists(S3Client::class)) {
            $this->markTestSkipped('RUSTFS_* is not configured.');
        }

        config(['filesystems.disks.rustfs_range' => [
            'driver' => 's3',
            'key' => env('RUSTFS_ACCESS_KEY_ID'),
            'secret' => env('RUSTFS_SECRET_ACCESS_KEY'),
            'region' => env('RUSTFS_REGION', 'us-east-1'),
            'bucket' => env('RUSTFS_BUCKET'),
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]]);

        $disk = Storage::disk('rustfs_range');
        $path = 'range-reader-test/'.bin2hex(random_bytes(6)).'.bin';

        try {
            $disk->put($path, str_repeat('a', 4 * 1024 * 1024).'MARKER'.str_repeat('b', 4 * 1024 * 1024));
        } catch (Throwable) {
            $this->markTestSkipped('RustFS is not reachable.');
        }

        try {
            $this->assertSame('MARKER', (new ObjectRangeReader)->read('rustfs_range', $path, 4 * 1024 * 1024, 6));
            $this->assertSame('aM', (new ObjectRangeReader)->read('rustfs_range', $path, 4 * 1024 * 1024 - 1, 2));
        } finally {
            $disk->delete($path);
        }
    }
}
