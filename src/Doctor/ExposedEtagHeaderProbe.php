<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks whether the presigned part PUT response exposes an ETag header.
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
        $headers = $context->lastPartHeaders;

        if (empty($headers)) {
            $key = $context->scratchPrefix.'etag-check.bin';
            $uploadId = null;

            try {
                $created = $context->internalClient->createMultipartUpload([
                    'Bucket' => $context->bucket,
                    'Key' => $key,
                ]);

                $uploadId = (string) $created['UploadId'];
                $context->trackMultipart($uploadId, $key);

                $url = $context->presigner->part($context->disk, $key, $uploadId, 1, now()->addMinutes(5));
                $response = Http::withBody('test', 'application/octet-stream')->put($url);
                $headers = $response->headers();

                $context->internalClient->abortMultipartUpload([
                    'Bucket' => $context->bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                ]);
                $context->untrackMultipart($uploadId);
            } catch (Throwable) {
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
            }
        }

        $hasEtag = false;
        foreach ($headers as $name => $values) {
            if (strcasecmp($name, 'ETag') === 0 && ! empty($values)) {
                $hasEtag = true;
                break;
            }
        }

        if ($hasEtag) {
            return ProbeResult::pass($this->name(), 'ETag header is exposed in part upload responses.');
        }

        return ProbeResult::fail(
            $this->name(),
            'ETag header is missing in part upload responses.',
            "Expose the ETag header in your bucket's CORS configuration (see php artisan documents:configure-bucket-cors)."
        );
    }
}
