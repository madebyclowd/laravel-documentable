<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

/**
 * Checks that CreateMultipartUpload accepts object metadata. A proxy that rewrites the header makes every upload
 * fail, which is why metadata is off by default.
 *
 * @internal
 */
final class MultipartCreateWithMetadataProbe extends MultipartCreateProbe
{
    public function name(): string
    {
        return 'MultipartCreateWithMetadata';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        return $this->createAndAbort(
            $context,
            $context->scratchPrefix.'mp-meta-test.bin',
            ['Metadata' => ['doctor' => 'probe']],
            'CreateMultipartUpload succeeded with metadata.',
            'CreateMultipartUpload with metadata failed',
            'Disable object metadata in config (DOCUMENTABLE_MULTIPART_OBJECT_METADATA=false) or configure your proxy to forward metadata headers.'
        );
    }
}
