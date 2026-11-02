<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Throwable;

/**
 * Opens and aborts a multipart upload to see whether the provider accepts it. The two concrete probes differ only
 * in the request they send and the advice they give.
 *
 * @internal
 */
abstract class MultipartCreateProbe implements Probe
{
    /**
     * @param  array<string, mixed>  $options  further CreateMultipartUpload parameters
     */
    protected function createAndAbort(DoctorContext $context, string $key, array $options, string $passMessage, string $failurePrefix, string $fix): ProbeResult
    {
        try {
            $context->abortMultipart($context->openMultipart($key, $options));

            return ProbeResult::pass($this->name(), $passMessage);
        } catch (Throwable $e) {
            return ProbeResult::fail($this->name(), "{$failurePrefix}: {$e->getMessage()}", $fix);
        }
    }
}
