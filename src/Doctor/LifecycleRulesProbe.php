<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use MadeByClowd\Documentable\Services\PrefixNormalizer;
use MadeByClowd\Documentable\Services\S3ExceptionMapper;
use Throwable;

/**
 * Checks that the bucket backs the app-level reaper with its own lifecycle rules: incomplete multipart uploads are
 * aborted bucket-wide, and the doctor's scratch prefix expires.
 *
 * @internal
 */
final class LifecycleRulesProbe implements Probe
{
    public function name(): string
    {
        return 'LifecycleRules';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        try {
            $rules = $context->internalClient->getBucketLifecycleConfiguration(['Bucket' => $context->bucket])['Rules'] ?? [];
        } catch (Throwable $e) {
            return S3ExceptionMapper::isAccessDeniedOrNotImplemented($e)
                ? ProbeResult::skipped(
                    $this->name(),
                    "Cannot read the bucket's lifecycle settings with this token (object read and write only?).",
                    'Check them in the provider dashboard.'
                )
                : ProbeResult::fail($this->name(), "Could not read the lifecycle configuration: {$e->getMessage()}", $this->fix($context));
        }

        $enabled = array_filter($rules, fn (array $rule) => ($rule['Status'] ?? '') === 'Enabled');
        $scratchPrefix = PrefixNormalizer::normalize((string) config('documentable.doctor.scratch_prefix', 'documentable-doctor'));

        $missing = array_filter([
            $this->hasRule($enabled, 'AbortIncompleteMultipartUpload', '') ? null : 'abort incomplete multipart uploads',
            $this->hasRule($enabled, 'Expiration', $scratchPrefix) ? null : 'expire the doctor scratch prefix',
        ]);

        return $missing === []
            ? ProbeResult::pass($this->name(), 'The bucket aborts incomplete multipart uploads and expires the doctor scratch prefix.')
            : ProbeResult::fail($this->name(), 'The bucket has no lifecycle rule to: '.implode(' and ', $missing).'.', $this->fix($context));
    }

    /**
     * Whether an enabled rule of this kind covers every key under $keyPrefix.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function hasRule(array $rules, string $action, string $keyPrefix): bool
    {
        foreach ($rules as $rule) {
            $rulePrefix = (string) ($rule['Filter']['Prefix'] ?? $rule['Filter']['And']['Prefix'] ?? $rule['Prefix'] ?? '');

            if (! empty($rule[$action]) && ($rulePrefix === '' || str_starts_with($keyPrefix, $rulePrefix))) {
                return true;
            }
        }

        return false;
    }

    private function fix(DoctorContext $context): string
    {
        return "Run php artisan documents:configure-bucket-lifecycle {$context->disk} to configure lifecycle rules.";
    }
}
