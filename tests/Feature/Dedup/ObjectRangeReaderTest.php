<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Dedup;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
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

    /**
     * A disk whose `getClient()` is the given S3 client, so the S3 branch runs without a network.
     */
    private function s3Disk(S3Client $client): void
    {
        $driver = new LocalFilesystemAdapter(sys_get_temp_dir());
        $disk = new class(new Filesystem($driver), $driver, [], $client) extends FilesystemAdapter
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

        Storage::set('s3_mock', $disk);
        config(['filesystems.disks.s3_mock.bucket' => 'bucket-x']);
    }

    private function mockedClient(callable ...$handlers): S3Client
    {
        $mock = new MockHandler($handlers);

        return new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);
    }

    public function test_the_s3_branch_asks_for_exactly_the_range(): void
    {
        $seen = null;
        $this->s3Disk($this->mockedClient(function (CommandInterface $command) use (&$seen) {
            $seen = $command->toArray();

            return new Result(['Body' => Utils::streamFor('3456')]);
        }));

        $this->assertSame('3456', (new ObjectRangeReader)->read('s3_mock', 'a.bin', 3, 4));
        $this->assertSame('bytes=3-6', $seen['Range']);
        $this->assertSame('bucket-x', $seen['Bucket']);
        $this->assertSame('a.bin', $seen['Key']);
    }

    public function test_the_s3_branch_fails_when_the_store_returns_fewer_bytes(): void
    {
        $this->s3Disk($this->mockedClient(fn () => new Result(['Body' => Utils::streamFor('34')])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('past the end');
        (new ObjectRangeReader)->read('s3_mock', 'a.bin', 3, 4);
    }

    public function test_the_s3_branch_wraps_a_store_error(): void
    {
        $this->s3Disk($this->mockedClient(fn (CommandInterface $command) => new AwsException('boom', $command)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not read a range');
        (new ObjectRangeReader)->read('s3_mock', 'a.bin', 0, 1);
    }

    public function test_it_refuses_an_invalid_range(): void
    {
        $this->expectException(RuntimeException::class);
        (new ObjectRangeReader)->read('test_disk', 'a.bin', -1, 4);
    }

    public function test_it_refuses_an_empty_range(): void
    {
        $this->expectException(RuntimeException::class);
        (new ObjectRangeReader)->read('test_disk', 'a.bin', 0, 0);
    }

    public function test_it_fails_when_the_stream_cannot_seek(): void
    {
        stream_wrapper_register('unseekable', UnseekableStream::class);

        try {
            $driver = new LocalFilesystemAdapter(sys_get_temp_dir());
            Storage::set('unseekable_disk', new class(new Filesystem($driver), $driver, []) extends FilesystemAdapter
            {
                public function readStream($path)
                {
                    return fopen('unseekable://data', 'r');
                }
            });

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Could not seek');
            (new ObjectRangeReader)->read('unseekable_disk', 'a.bin', 3, 4);
        } finally {
            stream_wrapper_unregister('unseekable');
        }
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

/**
 * A stream that reads but has no stream_seek(), so fseek() on it fails.
 */
class UnseekableStream
{
    /** @var resource|null */
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $this->position += $count;

        return str_repeat('x', $count);
    }

    public function stream_eof(): bool
    {
        return $this->position > 100;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return [];
    }
}
