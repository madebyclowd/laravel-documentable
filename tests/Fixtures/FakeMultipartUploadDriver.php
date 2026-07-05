<?php

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;

/**
 * Simulates a provider's multipart API against a faked local disk, so the
 * DocumentService orchestration (ownership checks, etag_strategy branching,
 * part reconciliation, integrity verification) can be exercised without a
 * real S3-compatible bucket. Tests drive "client part upload" via
 * uploadPart(), which is what a real client's presigned PUT would do.
 */
class FakeMultipartUploadDriver implements MultipartUploadDriver
{
    /** @var array<string, array<int, array{etag: string, body: string}>> */
    public static array $sessions = [];

    public function create(string $disk, string $path, string $filename): array
    {
        $uploadId = (string) Str::uuid();

        static::$sessions[$uploadId] = [];

        return ['upload_id' => $uploadId];
    }

    public function presignPartUpload(string $disk, string $path, string $uploadId, int $partNumber): string
    {
        return "fake://{$uploadId}/{$partNumber}";
    }

    public function listParts(string $disk, string $path, string $uploadId): array
    {
        $parts = static::$sessions[$uploadId] ?? [];
        ksort($parts);

        return collect($parts)
            ->map(fn ($part, $partNumber) => ['PartNumber' => $partNumber, 'ETag' => $part['etag']])
            ->values()
            ->all();
    }

    public function complete(string $disk, string $path, string $uploadId, array $parts): void
    {
        $stored = static::$sessions[$uploadId] ?? [];
        ksort($stored);

        $body = '';
        foreach ($stored as $part) {
            $body .= $part['body'];
        }

        Storage::disk($disk)->put($path, $body);

        unset(static::$sessions[$uploadId]);
    }

    public function abort(string $disk, string $path, string $uploadId): void
    {
        unset(static::$sessions[$uploadId]);
    }

    /**
     * Test helper simulating a client's presigned PUT of one part's bytes.
     */
    public static function uploadPart(string $uploadId, int $partNumber, string $body): string
    {
        $etag = '"'.md5($body).'"';

        static::$sessions[$uploadId][$partNumber] = ['etag' => $etag, 'body' => $body];

        return $etag;
    }

    public static function reset(): void
    {
        static::$sessions = [];
    }
}
