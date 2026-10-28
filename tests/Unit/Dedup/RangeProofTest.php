<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit\Dedup;

use MadeByClowd\Documentable\Dedup\RangeProof;
use PHPUnit\Framework\TestCase;

class RangeProofTest extends TestCase
{
    public function test_it_reproduces_all_committed_vectors(): void
    {
        $fixturePath = __DIR__.'/../../Fixtures/range-proof-vectors.json';
        $this->assertFileExists($fixturePath);

        $json = file_get_contents($fixturePath);
        $this->assertIsString($json);

        /** @var list<array{nonce: string, bytes: string, ranges: list<array{offset: int, length: int}>, expected_proof: string}> $vectors */
        $vectors = json_decode($json, true);
        $this->assertIsArray($vectors);
        $this->assertNotEmpty($vectors);

        foreach ($vectors as $i => $vector) {
            $bytes = hex2bin($vector['bytes']);
            $this->assertIsString($bytes, "Vector {$i} bytes should be valid hex");

            $proof = RangeProof::computeFromBytes($vector['nonce'], $bytes, $vector['ranges']);
            $this->assertSame(
                $vector['expected_proof'],
                $proof,
                "Vector {$i} failed to reproduce expected proof"
            );
        }
    }
}
