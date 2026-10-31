<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

/**
 * Checks that CreateMultipartUpload works at all, with the request the package sends by default.
 *
 * @internal
 */
final class MultipartCreateWithoutMetadataProbe extends MultipartCreateProbe
{
    public function name(): string
    {
        return 'MultipartCreateWithoutMetadata';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        return $this->createAndAbort(
            $context,
            $context->scratchPrefix.'mp-no-meta-test.bin',
            [],
            'CreateMultipartUpload succeeded without metadata.',
            'CreateMultipartUpload failed',
            'Verify credentials have s3:PutObject permission and provider supports multipart uploads.'
        );
    }
}
