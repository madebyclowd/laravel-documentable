<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks multipart upload part PUT, part listing, and completion using presigned part URLs.
 *
 * @internal
 */
final class UploadPartProbe implements Probe
{
    public function name(): string
    {
        return 'UploadPart';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        $key = $context->scratchPrefix.'upload-part-test.bin';
        $uploadId = null;

        try {
            $created = $context->internalClient->createMultipartUpload([
                'Bucket' => $context->bucket,
                'Key' => $key,
            ]);

            $uploadId = (string) $created['UploadId'];
            $context->trackMultipart($uploadId, $key);

            // Part 1: >= 5 MiB
            $part1Size = 5 * 1024 * 1024;
            $part1Body = str_repeat('A', $part1Size);
            $part1Url = $context->presigner->part($context->disk, $key, $uploadId, 1, now()->addMinutes(10));

            $response1 = Http::withBody($part1Body, 'application/octet-stream')->put($part1Url);
            if (! $response1->successful()) {
                throw new \RuntimeException("Presigned upload of part 1 failed with HTTP {$response1->status()}: {$response1->body()}");
            }

            $context->lastPartHeaders = $response1->headers();

            // Part 2: 1 KiB
            $part2Body = str_repeat('B', 1024);
            $part2Url = $context->presigner->part($context->disk, $key, $uploadId, 2, now()->addMinutes(10));

            $response2 = Http::withBody($part2Body, 'application/octet-stream')->put($part2Url);
            if (! $response2->successful()) {
                throw new \RuntimeException("Presigned upload of part 2 failed with HTTP {$response2->status()}: {$response2->body()}");
            }

            $listed = $context->internalClient->listParts([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);

            $parts = [];
            foreach ($listed['Parts'] ?? [] as $part) {
                $parts[] = [
                    'PartNumber' => (int) $part['PartNumber'],
                    'ETag' => (string) $part['ETag'],
                ];
            }

            $context->internalClient->completeMultipartUpload([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ]);

            $context->untrackMultipart($uploadId);

            return ProbeResult::pass($this->name(), 'Multipart upload, part listing, and completion succeeded.');
        } catch (Throwable $e) {
            if ($uploadId !== null) {
                try {
                    $context->internalClient->abortMultipartUpload([
                        'Bucket' => $context->bucket,
                        'Key' => $key,
                        'UploadId' => $uploadId,
                    ]);
                } catch (Throwable) {
                    // Ignore cleanup error
                }
                $context->untrackMultipart($uploadId);
            }

            return ProbeResult::fail(
                $this->name(),
                "UploadPart probe failed: {$e->getMessage()}",
                'Verify CORS configuration allows PUT requests and bucket policy permits multipart upload actions.'
            );
        }
    }
}
