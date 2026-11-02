<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Checks that deleting an object succeeds and subsequent exists check returns false.
 *
 * @internal
 */
final class DeleteAndExistsProbe implements Probe
{
    public function name(): string
    {
        return 'DeleteAndExists';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        $key = $context->scratchPrefix.'delete-exists-test.txt';

        try {
            $context->internalClient->putObject([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'Body' => 'delete-test',
            ]);

            Storage::disk($context->disk)->delete($key);

            $exists = Storage::disk($context->disk)->exists($key);

            if ($exists) {
                return ProbeResult::fail(
                    $this->name(),
                    'Object still reports as existing after deletion.',
                    'Check bucket consistency model and delete permissions (s3:DeleteObject).'
                );
            }

            $throw = (bool) config("filesystems.disks.{$context->disk}.throw", false);

            $message = 'Object was deleted and verified not present.';
            if ($throw) {
                $message .= ' Note: disk setting throw is true (Storage::exists/delete raise exceptions instead of returning false, which the package handles).';
            }

            return ProbeResult::pass($this->name(), $message);
        } catch (Throwable $e) {
            return ProbeResult::fail(
                $this->name(),
                "DeleteAndExists failed: {$e->getMessage()}",
                'Verify credentials have s3:DeleteObject permissions.'
            );
        }
    }
}
