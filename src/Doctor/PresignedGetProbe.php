<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks that an object can be fetched over HTTP through a presigned download URL.
 *
 * @internal
 */
final class PresignedGetProbe implements Probe
{
    public function name(): string
    {
        return 'PresignedGet';
    }

    public function run(DoctorContext $context): ProbeResult
    {
        $key = $context->scratchPrefix.'presigned-get-test.txt';
        $content = 'doctor-presigned-get-payload';

        try {
            $context->internalClient->putObject([
                'Bucket' => $context->bucket,
                'Key' => $key,
                'Body' => $content,
            ]);

            $url = $context->presigner->download(
                $context->disk,
                $key,
                now()->addMinutes(5),
                'inline; filename="test.txt"'
            );

            $response = Http::get($url);

            if (! $response->successful()) {
                return ProbeResult::fail(
                    $this->name(),
                    "Presigned GET returned HTTP {$response->status()}: {$response->body()}",
                    'Verify presigned download URL generation, endpoint accessibility, and s3:GetObject permissions.'
                );
            }

            if ($response->body() !== $content) {
                return ProbeResult::fail(
                    $this->name(),
                    'Presigned GET returned unexpected payload content.',
                    'Check proxy or CDN caches between the application and storage provider.'
                );
            }

            return ProbeResult::pass($this->name(), 'Presigned GET download succeeded.');
        } catch (Throwable $e) {
            return ProbeResult::fail(
                $this->name(),
                "Presigned GET failed: {$e->getMessage()}",
                'Ensure presign endpoint is reachable and credentials have s3:GetObject permissions.'
            );
        }
    }
}
