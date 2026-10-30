<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Throwable;

/**
 * Checks that CreateMultipartUpload succeeds without metadata.
 *
 * @internal
 */
final class MultipartCreateWithoutMetadataProbe implements Probe
{
    public function name(): string
    {
        return 'MultipartCreateWithoutMetadata';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        $key = $context->scratchPrefix.'mp-no-meta-test.bin';
        $uploadId = null;

        try {
            $result = $context->internalClient->createMultipartUpload([
                'Bucket' => $context->bucket,
                'Key' => $key,
            ]);

            $uploadId = (string) $result['UploadId'];
            $context->trackMultipart($uploadId, $key);

            $context->internalClient->abortMultipartUpload([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
            $context->untrackMultipart($uploadId);

            return ProbeResult::pass($this->name(), 'CreateMultipartUpload succeeded without metadata.');
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
                "CreateMultipartUpload failed: {$e->getMessage()}",
                'Verify credentials have s3:PutObject permission and provider supports multipart uploads.'
            );
        }
    }
}
