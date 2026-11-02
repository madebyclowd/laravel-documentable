<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use MadeByClowd\Documentable\Services\ObjectRangeReader;
use Throwable;

/**
 * Checks that the storage provider supports ranged GET requests.
 *
 * @internal
 */
final class RangedGetProbe implements Probe
{
    public function name(): string
    {
        return 'RangedGet';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        $key = $context->scratchPrefix.'ranged-get-test.txt';

        try {
            $context->internalClient->putObject([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'Body' => '0123456789',
            ]);

            $reader = new ObjectRangeReader;
            $bytes = $reader->read($context->disk, $key, 0, 4);

            if ($bytes !== '0123') {
                return ProbeResult::fail(
                    $this->name(),
                    "Expected 4 bytes '0123', got '".addcslashes($bytes, "\0..\37")."'",
                    'Verify the storage provider honors the HTTP Range header.'
                );
            }

            return ProbeResult::pass($this->name(), 'Ranged GET returned exactly 4 bytes.');
        } catch (Throwable $e) {
            return ProbeResult::fail(
                $this->name(),
                "Ranged GET failed: {$e->getMessage()}",
                'Ensure the storage provider supports HTTP byte range requests.'
            );
        }
    }
}
