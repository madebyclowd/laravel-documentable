<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Reads one byte range of a stored object. On an S3-compatible disk that is a single ranged GET, so the cost of a
 * dedup proof does not grow with the size of the object. Any other disk is read through a stream, seeking when the
 * stream allows it.
 */
final class ObjectRangeReader
{
    /**
     * @throws RuntimeException when the object is missing or holds fewer bytes than the range asks for
     */
    public function read(string $disk, string $path, int $offset, int $length): string
    {
        if ($offset < 0 || $length <= 0) {
            throw new RuntimeException("Invalid range [{$offset}, {$length}] for [{$path}].");
        }

        $client = S3ClientDetector::client($disk);

        $bytes = $client !== null
            ? $this->readS3($client, $disk, $path, $offset, $length)
            : $this->readStream($disk, $path, $offset, $length);

        if (strlen($bytes) !== $length) {
            throw new RuntimeException("Range [{$offset}, {$length}] is past the end of [{$path}] on disk [{$disk}].");
        }

        return $bytes;
    }

    private function readS3(S3Client $client, string $disk, string $path, int $offset, int $length): string
    {
        try {
            $result = $client->getObject([
                'Bucket' => S3ClientDetector::bucket($disk),
                'Key' => $path,
                'Range' => 'bytes='.$offset.'-'.($offset + $length - 1),
            ]);
        } catch (Throwable $e) {
            throw new RuntimeException("Could not read a range of [{$path}] on disk [{$disk}]: {$e->getMessage()}", 0, $e);
        }

        return (string) $result['Body'];
    }

    private function readStream(string $disk, string $path, int $offset, int $length): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not open [{$path}] on disk [{$disk}] for reading.");
        }

        try {
            // fseek() warns as well as returning -1 on a stream that cannot seek; the return value is the signal.
            if (@fseek($stream, $offset) !== 0) {
                throw new RuntimeException("Could not seek to {$offset} in [{$path}] on disk [{$disk}].");
            }

            return (string) stream_get_contents($stream, $length);
        } finally {
            fclose($stream);
        }
    }
}
