<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

/**
 * @internal
 */
final class ServerSideEncryption
{
    /**
     * Explicit SSE, passed through rather than relying on the bucket's
     * default encryption setting (security-risks.md #2).
     *
     * @return array{ServerSideEncryption?: string, SSEKMSKeyId?: string}
     */
    public static function options(string $disk): array
    {
        $sse = config("documentable.disks.{$disk}.server_side_encryption");

        if (! $sse) {
            return [];
        }

        $options = ['ServerSideEncryption' => $sse];

        if ($sse === 'aws:kms' && $kmsKeyId = config("documentable.disks.{$disk}.kms_key_id")) {
            $options['SSEKMSKeyId'] = $kmsKeyId;
        }

        return $options;
    }
}
