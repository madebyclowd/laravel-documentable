<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Checks multipart upload part PUT, part listing, and completion using presigned part URLs. The first part is the
 * provider minimum and the second is smaller, so the upload also has equal parts except the last, which Cloudflare
 * R2 requires.
 *
 * @internal
 */
final class UploadPartProbe implements Probe
{
    private const FIRST_PART_BYTES = 5 * 1024 * 1024;

    private const LAST_PART_BYTES = 1024;

    public function name(): string
    {
        return 'UploadPart';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        $key = $context->scratchPrefix.'upload-part-test.bin';

        try {
            $uploadId = $context->openMultipart($key);

            $context->lastPartHeaders = $this->putPart($context, $key, $uploadId, 1, self::FIRST_PART_BYTES);
            $this->putPart($context, $key, $uploadId, 2, self::LAST_PART_BYTES);

            $listed = $context->internalClient->listParts(['Bucket' => $context->bucket, 'Key' => $key, 'UploadId' => $uploadId]);

            $context->internalClient->completeMultipartUpload([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => array_map(
                    fn (array $part) => ['PartNumber' => (int) $part['PartNumber'], 'ETag' => (string) $part['ETag']],
                    $listed['Parts'] ?? []
                )],
            ]);
            $context->untrackMultipart($uploadId);

            return ProbeResult::pass($this->name(), 'Multipart upload, part listing, and completion succeeded.');
        } catch (Throwable $e) {
            return ProbeResult::fail(
                $this->name(),
                "UploadPart probe failed: {$e->getMessage()}",
                'Verify CORS configuration allows PUT requests and bucket policy permits multipart upload actions.'
            );
        }
    }

    /**
     * Sends one part through a presigned URL and returns the response headers.
     *
     * @return array<string, list<string>>
     */
    private function putPart(DoctorContext $context, string $key, string $uploadId, int $partNumber, int $bytes): array
    {
        $url = $context->presigner->part($context->disk, $key, $uploadId, $partNumber, now()->addMinutes(10));
        $response = Http::withBody(str_repeat('A', $bytes), 'application/octet-stream')->put($url);

        if (! $response->successful()) {
            throw new RuntimeException("Presigned upload of part {$partNumber} failed with HTTP {$response->status()}: {$response->body()}");
        }

        return $response->headers();
    }
}
