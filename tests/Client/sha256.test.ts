import { describe, it, expect } from 'vitest';
import crypto from 'node:crypto';
import { Sha256, hashBlob, UploadError } from '../../resources/client/documentable.js';

describe('Sha256 pure TypeScript implementation', () => {
  it('hashes the empty string', () => {
    const sha = new Sha256();
    expect(sha.digest()).toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
  });

  it('hashes "abc"', () => {
    const sha = new Sha256();
    sha.update('abc');
    expect(sha.digest()).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
  });

  it('hashes the NIST two-block message', () => {
    const nistMessage = 'abcdbcdecdefdefgefghfghighijhijkijkljklmklmnlmnomnopnopq';
    const sha = new Sha256();
    sha.update(nistMessage);
    expect(sha.digest()).toBe('248d6a61d20638b8e5c026930c3e6039a33ce45964ff2167f6ecedd419db06c1');
  });

  it('hashes one million "a"', () => {
    const sha = new Sha256();
    const chunk = 'a'.repeat(1000);
    for (let i = 0; i < 1000; i++) {
      sha.update(chunk);
    }
    expect(sha.digest()).toBe('cdc76e5c9914fb9281a1c7e284d73e67f1809a48a497200e046d39ccc7112cd0');
  });

  it('matches node:crypto for random buffers fed in uneven chunks', () => {
    const sizes = [0, 1, 55, 56, 63, 64, 65, 4 * 1024 * 1024, 4 * 1024 * 1024 + 1];
    const chunkSizes = [1, 7, 13, 31, 64, 100, 1024, 65536];

    for (const size of sizes) {
      const buffer = crypto.randomBytes(size);
      const expected = crypto.createHash('sha256').update(buffer).digest('hex');

      const sha = new Sha256();
      let pos = 0;
      let ci = 0;
      while (pos < size) {
        const chunkSize = Math.min(chunkSizes[ci % chunkSizes.length]!, size - pos);
        ci++;
        sha.update(buffer.subarray(pos, pos + chunkSize));
        pos += chunkSize;
      }

      expect(sha.digest()).toBe(expected);
    }
  });
});

describe('hashBlob', () => {
  it('hashes a Blob in 4 MiB slices', async () => {
    const content = 'Test blob content for sha256 hashing';
    const blob = new Blob([content]);
    const expected = crypto.createHash('sha256').update(content).digest('hex');

    const result = await hashBlob(blob);
    expect(result).toBe(expected);
  });

  it('aborts when signal is triggered', async () => {
    const controller = new AbortController();
    controller.abort();
    const blob = new Blob(['some content']);

    await expect(hashBlob(blob, controller.signal)).rejects.toThrow(UploadError);
    await expect(hashBlob(blob, controller.signal)).rejects.toMatchObject({
      code: 'aborted',
    });
  });
});
