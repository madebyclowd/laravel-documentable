<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Throwable;

/**
 * Finds out whether the provider rejects the checksum headers the AWS SDK adds by default (Cloudflare R2 and
 * Backblaze B2 do). It writes the same small object twice, once with the SDK default and once with checksums only
 * where the API requires them, so the result comes from the provider and not from reading a setting.
 *
 * @internal
 */
final class ChecksumHeadersProbe implements Probe
{
    public function name(): string
    {
        return 'ChecksumHeaders';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        if ($this->putAccepted($context, 'when_supported')) {
            return ProbeResult::pass($this->name(), 'The provider accepts the SDK default checksum headers.');
        }

        if ($this->putAccepted($context, 'when_required')) {
            return ProbeResult::fail(
                $this->name(),
                'The provider rejects the SDK default checksum headers.',
                "Add 'request_checksum_calculation' => 'when_required' and 'response_checksum_validation' => 'when_required' to the disk in config/filesystems.php. The package's own presigning already does this."
            );
        }

        return ProbeResult::skipped($this->name(), 'Could not tell: the test write failed with and without checksum headers. See the PutObject probe.');
    }

    private function putAccepted(DoctorContext $context, string $checksumMode): bool
    {
        try {
            $context->internalClient->putObject([
                'Bucket' => $context->bucket,
                'Key' => $context->scratchPrefix.'checksum-'.$checksumMode.'.txt',
                'Body' => 'doctor-checksum-test',
                '@context' => ['request_checksum_calculation' => $checksumMode],
            ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
