import { describe, it, expect } from 'vitest';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { computeRangeProof } from '../../resources/client/documentable.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

interface RangeVector {
  nonce: string;
  bytes: string;
  ranges: Array<{ offset: number; length: number }>;
  expected_proof: string;
}

function hexToBytes(hex: string): Uint8Array {
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < hex.length; i += 2) {
    bytes[i / 2] = parseInt(hex.slice(i, i + 2), 16);
  }
  return bytes;
}

describe('computeRangeProof', () => {
  it('matches every vector in tests/Fixtures/range-proof-vectors.json', () => {
    const fixturePath = path.resolve(__dirname, '../Fixtures/range-proof-vectors.json');
    const content = fs.readFileSync(fixturePath, 'utf8');
    const vectors = JSON.parse(content) as RangeVector[];

    expect(vectors.length).toBeGreaterThan(0);

    for (const vector of vectors) {
      const fullBytes = hexToBytes(vector.bytes);
      const slices = vector.ranges.map(r => fullBytes.subarray(r.offset, r.offset + r.length));
      const totalLength = slices.reduce((acc, s) => acc + s.length, 0);
      const concatenated = new Uint8Array(totalLength);
      let offset = 0;
      for (const slice of slices) {
        concatenated.set(slice, offset);
        offset += slice.length;
      }
      const proof = computeRangeProof(vector.nonce, concatenated);
      expect(proof).toBe(vector.expected_proof);
    }
  });
});
