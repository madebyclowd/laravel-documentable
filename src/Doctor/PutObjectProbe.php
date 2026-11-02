<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Throwable;

/**
 * Checks that the internal S3 client can write an object to the bucket.
 *
 * @internal
 */
final class PutObjectProbe implements Probe
{
    public function name(): string
    {
        return 'PutObject';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        $key = $context->scratchPrefix.'put-test.txt';

        try {
            $context->internalClient->putObject([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'Body' => 'doctor-put-test',
            ]);

            return ProbeResult::pass($this->name(), 'Internal S3 client wrote object successfully.');
        } catch (Throwable $e) {
            return ProbeResult::fail(
                $this->name(),
                "Internal PutObject failed: {$e->getMessage()}",
                'Check bucket write permissions (s3:PutObject) for the configured credentials.'
            );
        }
    }
}
