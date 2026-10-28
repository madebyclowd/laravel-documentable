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

    public function test_it_reproduces_proof_from_stream(): void
    {
        $nonce = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
        $content = 'The quick brown fox jumps over the lazy dog';
        $ranges = [
            ['offset' => 4, 'length' => 5],
            ['offset' => 16, 'length' => 5],
        ];

        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        $proofFromStream = RangeProof::computeFromStream($nonce, $stream, $ranges);
        fclose($stream);

        $expectedProof = RangeProof::computeFromBytes($nonce, $content, $ranges);
        $this->assertSame($expectedProof, $proofFromStream);
        $this->assertSame('c0db9c0f4fb07560dc88a67957c2ec7aea1f64bd0e1256c9ac50772176af9c9f', $expectedProof);
    }
}
