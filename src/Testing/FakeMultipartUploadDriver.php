<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Testing;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Exceptions\MultipartPartTooSmallException;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Models\UploadSession;
use Throwable;

/**
 * Simulates a provider's multipart API against a faked storage disk, so
 * multipart orchestration can be tested without a real S3 bucket.
 *
 * @api
 */
class FakeMultipartUploadDriver implements MultipartUploadDriver
{
    /** @var array<string, array{path: string, parts: array<int, array{etag: string, body: string}>}> */
    public static array $sessions = [];

    /**
     * When true, `create()` returns a 512 character upload id, the shape Cloudflare R2 produces (343
     * characters) and then some, so tests can prove the session table stores ids a varchar(255) cannot.
     */
    public static bool $longIds = false;

    /**
     * When true, `complete()` throws MultipartPartTooSmallException if a non-last part is smaller than 5 MiB.
     */
    public static bool $unequalParts = false;

    /**
     * Upload ids whose abort() throws the given exception, to simulate a provider that no longer knows the
     * upload or is unreachable.
     *
     * @var array<string, Throwable>
     */
    public static array $abortErrors = [];

    public function create(string $disk, string $path, string $filename): array
    {
        $uploadId = static::$longIds
            ? substr(str_repeat(Str::uuid()->toString().'-', 16), 0, 512)
            : (string) Str::uuid();

        static::$sessions[$uploadId] = [
            'path' => $path,
            'parts' => [],
        ];

        return ['upload_id' => $uploadId];
    }

    public function presignPartUpload(UploadSession $session, int $partNumber): string
    {
        $uploadId = (string) $session->upload_id;

        if (! isset(static::$sessions[$uploadId]) || static::$sessions[$uploadId]['path'] !== $session->path) {
            throw MultipartUploadNotFoundException::forUpload($session->path, $uploadId);
        }

        return "fake://{$uploadId}/{$partNumber}";
    }

    public function listParts(UploadSession $session): array
    {
        $uploadId = (string) $session->upload_id;

        if (! isset(static::$sessions[$uploadId]) || static::$sessions[$uploadId]['path'] !== $session->path) {
            throw MultipartUploadNotFoundException::forUpload($session->path, $uploadId);
        }

        $parts = static::$sessions[$uploadId]['parts'];
        ksort($parts);

        return collect($parts)
            ->map(fn ($part, $partNumber) => ['PartNumber' => (int) $partNumber, 'ETag' => $part['etag']])
            ->values()
            ->all();
    }

    public function complete(UploadSession $session, array $parts): void
    {
        $uploadId = (string) $session->upload_id;

        if (! isset(static::$sessions[$uploadId]) || static::$sessions[$uploadId]['path'] !== $session->path) {
            throw MultipartUploadNotFoundException::forUpload($session->path, $uploadId);
        }

        $stored = static::$sessions[$uploadId]['parts'];
        ksort($stored);

        if (static::$unequalParts && count($parts) > 1) {
            $sorted = $parts;
            usort($sorted, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
            $lastIndex = count($sorted) - 1;

            foreach ($sorted as $idx => $part) {
                if ($idx !== $lastIndex) {
                    $partNum = $part['PartNumber'];
                    $bodyLen = isset($stored[$partNum]) ? strlen($stored[$partNum]['body']) : 0;
                    if ($bodyLen < 5 * 1024 * 1024) {
                        throw MultipartPartTooSmallException::forUpload($session->path, $uploadId);
                    }
                }
            }
        }

        $body = '';
        foreach ($stored as $part) {
            $body .= $part['body'];
        }

        Storage::disk($session->disk)->put($session->path, $body);

        unset(static::$sessions[$uploadId]);
    }

    public function abort(UploadSession $session): void
    {
        $uploadId = (string) $session->upload_id;

        if (isset(static::$abortErrors[$uploadId])) {
            throw static::$abortErrors[$uploadId];
        }

        if (! isset(static::$sessions[$uploadId])) {
            throw MultipartUploadNotFoundException::forUpload($session->path, $uploadId);
        }

        unset(static::$sessions[$uploadId]);
    }

    /**
     * Test helper simulating a client's presigned PUT of one part's bytes.
     */
    public static function uploadPart(string $uploadId, int $partNumber, string $body): string
    {
        $etag = '"'.md5($body).'"';

        static::$sessions[$uploadId]['parts'][$partNumber] = ['etag' => $etag, 'body' => $body];

        return $etag;
    }

    public static function reset(): void
    {
        static::$sessions = [];
        static::$longIds = false;
        static::$unequalParts = false;
        static::$abortErrors = [];
    }
}
