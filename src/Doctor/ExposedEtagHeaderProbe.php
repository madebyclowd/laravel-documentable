<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Checks that a part upload through a presigned URL answers with an ETag header. A browser also needs the bucket's
 * CORS rules to expose it, which a request from the server cannot show.
 *
 * @internal
 */
final class ExposedEtagHeaderProbe implements Probe
{
    public function name(): string
    {
        return 'ExposedEtagHeader';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        try {
            $headers = $context->lastPartHeaders !== [] ? $context->lastPartHeaders : $this->uploadSmallPart($context);
        } catch (Throwable $e) {
            return ProbeResult::fail(
                $this->name(),
                "Could not check the ETag header: {$e->getMessage()}",
                'Verify credentials can create multipart uploads and that presigned part URLs are reachable (see the UploadPart probe).'
            );
        }

        if (array_change_key_case($headers, CASE_LOWER)['etag'] ?? []) {
            return ProbeResult::pass($this->name(), 'ETag header is exposed in part upload responses.');
        }

        return ProbeResult::fail(
            $this->name(),
            'ETag header is missing in part upload responses.',
            "Expose the ETag header in your bucket's CORS configuration (see php artisan documents:configure-bucket-cors)."
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function uploadSmallPart(DoctorContext $context): array
    {
        $key = $context->scratchPrefix.'etag-check.bin';
        $uploadId = $context->openMultipart($key);

        $url = $context->presigner->part($context->disk, $key, $uploadId, 1, now()->addMinutes(5));
        $response = Http::withBody('test', 'application/octet-stream')->put($url);

        if (! $response->successful()) {
            throw new RuntimeException("The part upload returned HTTP {$response->status()}");
        }

        $context->abortMultipart($uploadId);

        return $response->headers();
    }
}
