<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Dedup;

/**
 * Pure, dependency-free reference implementation of the proof-of-possession computation.
 *
 * Given a challenge nonce and a list of byte ranges, the proof is:
 *   hex sha256(nonce || concatenated bytes of the ranges in order)
 *
 * This implementation is shared by the server verifier, test fixtures, and can be used as a
 * specification for client helpers (such as the TypeScript SDK).
 */
final class RangeProof
{
    /**
     * Compute the proof from raw in-memory bytes of the full file.
     *
     * @param  list<array{offset: int, length: int}>  $ranges
     */
    public static function computeFromBytes(string $nonce, string $rawBytes, array $ranges): string
    {
        $concatenated = '';

        foreach ($ranges as $range) {
            $offset = $range['offset'];
            $length = $range['length'];

            $slice = substr($rawBytes, $offset, $length);
            $concatenated .= $slice;
        }

        return self::computeFromConcatenated($nonce, $concatenated);
    }

    /**
     * Compute the proof directly from already concatenated range bytes.
     */
    public static function computeFromConcatenated(string $nonce, string $concatenatedBytes): string
    {
        return hash('sha256', $nonce.$concatenatedBytes);
    }
}
