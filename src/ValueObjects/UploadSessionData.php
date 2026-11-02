<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\ValueObjects;

use InvalidArgumentException;

/**
 * What a client declares about an upload before sending it. None of it is trusted: the verification step
 * measures the real object and compares.
 *
 * @api
 */
final class UploadSessionData
{
    public readonly ?string $sha256;

    public function __construct(
        public readonly ?int $sizeBytes = null,
        public readonly ?string $mimeType = null,
        ?string $sha256 = null,
    ) {
        if ($sizeBytes !== null && $sizeBytes < 0) {
            throw new InvalidArgumentException('A declared size cannot be negative.');
        }

        if ($sha256 !== null && ! preg_match('/^[0-9a-fA-F]{64}$/', $sha256)) {
            throw new InvalidArgumentException('A declared hash must be 64 hexadecimal characters (SHA-256).');
        }

        $this->sha256 = $sha256 === null ? null : strtolower($sha256);
    }

    /**
     * @return array{declared_size: ?int, declared_mime: ?string, declared_hash: ?string}
     */
    public function toColumns(): array
    {
        return [
            'declared_size' => $this->sizeBytes,
            'declared_mime' => $this->mimeType,
            'declared_hash' => $this->sha256,
        ];
    }
}
