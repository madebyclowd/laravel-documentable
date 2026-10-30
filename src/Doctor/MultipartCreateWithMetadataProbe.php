<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Aws\S3\Exception\S3Exception;
use Throwable;

/**
 * Checks that CreateMultipartUpload succeeds when sending metadata.
 *
 * @internal
 */
final class MultipartCreateWithMetadataProbe implements Probe
{
    public function name(): string
    {
        return 'MultipartCreateWithMetadata';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        $key = $context->scratchPrefix.'mp-meta-test.bin';
        $uploadId = null;

        try {
            $result = $context->internalClient->createMultipartUpload([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'Metadata' => [
                    'doctor' => 'probe',
                ],
            ]);

            $uploadId = (string) $result['UploadId'];
            $context->trackMultipart($uploadId, $key);

            $context->internalClient->abortMultipartUpload([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
            $context->untrackMultipart($uploadId);

            return ProbeResult::pass($this->name(), 'CreateMultipartUpload succeeded with metadata.');
        } catch (S3Exception $e) {
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
                "CreateMultipartUpload with metadata failed: {$e->getMessage()}",
                'Disable object metadata in config (DOCUMENTABLE_MULTIPART_OBJECT_METADATA=false) or configure your proxy to forward metadata headers.'
            );
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
                "CreateMultipartUpload with metadata failed: {$e->getMessage()}",
                'Disable object metadata in config (DOCUMENTABLE_MULTIPART_OBJECT_METADATA=false) or configure your proxy to forward metadata headers.'
            );
        }
    }
}
