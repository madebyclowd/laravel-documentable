import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

describe('resources/client/documentable.ts size budget', () => {
  it('stays within the byte budget', () => {
    // The budget ensures documentable.ts remains a lightweight, single-file, dependency-free
    // helper suitable for vendor publishing into application asset pipelines without bloat.
    const BUDGET_BYTES = 40_000; // Current size (~37 KB) plus ~7% headroom
    const targetFile = path.resolve(__dirname, '../../resources/client/documentable.ts');
    const content = fs.readFileSync(targetFile, 'utf8');
    const byteLength = new TextEncoder().encode(content).length;

    expect(byteLength).toBeLessThanOrEqual(BUDGET_BYTES);
  });
});
