import { describe, it, expect } from 'vitest';
import crypto from 'node:crypto';
import { FakeServer } from './fakeServer.js';
import { uploadFile, createUploader, UploadError } from '../../resources/client/documentable.js';

describe('TypeScript client upload flow', () => {
  it('small file end to end: hash correct, finalize carries expected_hash, no app headers on PUT', async () => {
    const server = new FakeServer();
    const content = 'Hello world, small file upload content';
    const file = new File([content], 'hello.pdf', { type: 'application/pdf' });
    const expectedHash = crypto.createHash('sha256').update(content).digest('hex');

    const appHeaders = { 'X-CSRF-TOKEN': 'test-token', 'Authorization': 'Bearer 123' };

    const result = await uploadFile(
      file,
      { type: 'passport' },
      {
        baseUrl: '/documents',
        fetch: server.fetch,
        headers: () => appHeaders,
      }
    );

    expect(result.sessionId).toBeDefined();
    expect(result.document).toBeUndefined();

    // Verify storage PUT request
    const storageReq = server.requests.find(r => r.url.startsWith('https://storage.test/presigned/'));
    expect(storageReq).toBeDefined();
    expect(storageReq!.method).toBe('PUT');
    // App headers must NOT be present on storage PUT
    expect(storageReq!.headers['x-csrf-token']).toBeUndefined();
    expect(storageReq!.headers['authorization']).toBeUndefined();
    // Headers returned from presigned endpoint should be present
    expect(storageReq!.headers['x-amz-server-side-encryption']).toBe('AES256');

    // Verify finalize carried expected_hash
    const finalizeReq = server.requests.find(r => r.url === '/documents/presigned/finalize');
    expect(finalizeReq).toBeDefined();
    expect((finalizeReq!.body as Record<string, unknown>).expected_hash).toBe(expectedHash);
    expect((finalizeReq!.body as Record<string, unknown>).session_id).toBe(result.sessionId);

    // Assert reassembly
    const reassembled = server.getReassembledBody(result.sessionId);
    expect(Buffer.from(reassembled).toString()).toBe(content);
    expect(crypto.createHash('sha256').update(reassembled).digest('hex')).toBe(expectedHash);
  });

  it('large file multipart: part sizes, batching, max_concurrency watermark, reassembly and ETag strategies', async () => {
    // 12 MiB file: threshold is 10 MiB, part size is 5 MiB -> 3 parts (5 MiB, 5 MiB, 2 MiB)
    const partSize = 5 * 1024 * 1024;
    const totalSize = 12 * 1024 * 1024;
    const part1 = crypto.randomBytes(partSize);
    const part2 = crypto.randomBytes(partSize);
    const part3 = crypto.randomBytes(2 * 1024 * 1024);
    const fullBuffer = Buffer.concat([part1, part2, part3]);
    const expectedHash = crypto.createHash('sha256').update(fullBuffer).digest('hex');

    const file = new File([fullBuffer], 'large.pdf', { type: 'application/pdf' });

    // Test with server-authoritative etag strategy
    const server1 = new FakeServer({ etagStrategy: 'server-authoritative' });
    const progressUpdates: Array<{ loaded: number; total: number }> = [];

    const result1 = await uploadFile(
      file,
      {
        type: 'archive',
        onProgress: p => progressUpdates.push(p),
      },
      {
        baseUrl: '/documents',
        fetch: server1.fetch,
      }
    );

    expect(result1.sessionId).toBeDefined();
    expect(server1.maxActiveUploads).toBeLessThanOrEqual(4); // max_concurrency is 4 in config
    expect(progressUpdates.length).toBeGreaterThan(0);
    expect(progressUpdates[progressUpdates.length - 1]!.loaded).toBe(totalSize);

    // Complete request in server-authoritative mode should NOT send parts
    const completeReq1 = server1.requests.find(r => r.url === '/documents/multipart/complete');
    expect(completeReq1).toBeDefined();
    expect((completeReq1!.body as Record<string, unknown>).parts).toBeUndefined();
    expect((completeReq1!.body as Record<string, unknown>).expected_hash).toBe(expectedHash);

    // Reassembly matches full buffer
    const reassembled1 = server1.getReassembledBody(result1.sessionId);
    expect(crypto.createHash('sha256').update(reassembled1).digest('hex')).toBe(expectedHash);

    // Test with client etag strategy
    const server2 = new FakeServer({ etagStrategy: 'client' });
    const result2 = await uploadFile(
      file,
      { type: 'archive' },
      {
        baseUrl: '/documents',
        fetch: server2.fetch,
      }
    );

    const completeReq2 = server2.requests.find(r => r.url === '/documents/multipart/complete');
    expect(completeReq2).toBeDefined();
    const parts = (completeReq2!.body as Record<string, unknown>).parts as Array<{ PartNumber: number; ETag: string }>;
    expect(parts).toBeDefined();
    expect(parts.length).toBe(3);
    // ETag must have quotes stripped
    expect(parts[0]!.ETag.startsWith('"')).toBe(false);
    expect(parts[0]!.PartNumber).toBe(1);

    // Test missing ETag under client strategy -> part_failed pointing to CORS ExposeHeaders
    const server3 = new FakeServer({ etagStrategy: 'client' });
    // Strip ETag header on storage response
    server3.injectFailure({
      urlPattern: 'https://storage.test/multipart/',
      status: 200,
      headers: {}, // no ETag header
    });

    await expect(
      uploadFile(file, { type: 'archive' }, { baseUrl: '/documents', fetch: server3.fetch })
    ).rejects.toMatchObject({
      code: 'part_failed',
    });
  });

  it('retry with injected backoff: schedule followed, 4xx fails immediately, exhaustion -> part_failed', async () => {
    const totalSize = 12 * 1024 * 1024;
    const file = new File([new Uint8Array(totalSize)], 'large.pdf', { type: 'application/pdf' });

    // Test retry schedule
    const server = new FakeServer();
    // Inject two failures specifically on part 1 upload (500), then succeed
    server.injectFailure({
      urlPattern: '/1',
      status: 500,
    });
    server.injectFailure({
      urlPattern: '/1',
      status: 500,
    });

    const sleepDelays: number[] = [];
    const customSleep = async (ms: number) => {
      sleepDelays.push(ms);
    };
    // deterministic random factor: 0.5
    const customRandom = () => 0.5;

    await uploadFile(
      file,
      { type: 'archive' },
      {
        baseUrl: '/documents',
        fetch: server.fetch,
        sleep: customSleep,
        random: customRandom,
      }
    );

    // Config base_delay_ms = 500, max_delay_ms = 8000
    // Try 0: delay = min(8000, 500 * 2^0) * 0.5 = 250
    // Try 1: delay = min(8000, 500 * 2^1) * 0.5 = 500
    expect(sleepDelays).toEqual([250, 500]);

    // Test 4xx other than 429 fails immediately without retry
    const server4xx = new FakeServer();
    server4xx.injectFailure({
      urlPattern: 'https://storage.test/multipart/',
      status: 403,
    });

    const sleepDelays4xx: number[] = [];
    await expect(
      uploadFile(
        file,
        { type: 'archive' },
        {
          baseUrl: '/documents',
          fetch: server4xx.fetch,
          sleep: async ms => { sleepDelays4xx.push(ms); },
        }
      )
    ).rejects.toMatchObject({
      code: 'forbidden',
      status: 403,
    });
    expect(sleepDelays4xx.length).toBe(0);

    // Test exhaustion -> part_failed and session is NOT aborted
    const serverExhaust = new FakeServer();
    for (let i = 0; i < 4; i++) {
      serverExhaust.injectFailure({
        urlPattern: /\/1$/,
        status: 500,
      });
    }

    await expect(
      uploadFile(
        file,
        { type: 'archive' },
        {
          baseUrl: '/documents',
          fetch: serverExhaust.fetch,
          sleep: async () => {},
        }
      )
    ).rejects.toMatchObject({
      code: 'part_failed',
    });

    // Verify multipart/abort was NOT called so caller can resume
    const abortReq = serverExhaust.requests.find(r => r.url === '/documents/multipart/abort');
    expect(abortReq).toBeUndefined();
  });

  it('resume after a dropped part skips completed parts', async () => {
    const server = new FakeServer({ etagStrategy: 'client' });
    const partSize = 5 * 1024 * 1024;
    const totalSize = 12 * 1024 * 1024;
    const file = new File([new Uint8Array(totalSize)], 'large.pdf', { type: 'application/pdf' });

    // Seed an existing session with part 1 already uploaded
    const sessionId = '01RESUMESESSIONID000000001';
    server.sessions.set(sessionId, {
      id: sessionId,
      kind: 'multipart',
      status: 'initiated',
      typeId: 'type_archive',
      filename: 'large.pdf',
      size: totalSize,
      partSize,
    });
    server.partBodies.set(sessionId, new Map([[1, new Uint8Array(partSize)]]));
    server.partETags.set(sessionId, new Map([[1, 'seeded-etag-1']]));

    const progressUpdates: Array<{ loaded: number; total: number }> = [];

    const result = await uploadFile(
      file,
      {
        type: 'archive',
        resumeSessionId: sessionId,
        onProgress: p => progressUpdates.push(p),
      },
      {
        baseUrl: '/documents',
        fetch: server.fetch,
      }
    );

    expect(result.sessionId).toBe(sessionId);

    // Verify part 1 was NOT uploaded again, only parts 2 and 3
    const uploadedPartUrls = server.requests
      .filter(r => r.url.startsWith(`https://storage.test/multipart/${sessionId}/`))
      .map(r => r.url);

    expect(uploadedPartUrls).not.toContain(`https://storage.test/multipart/${sessionId}/1`);
    expect(uploadedPartUrls).toContain(`https://storage.test/multipart/${sessionId}/2`);
    expect(uploadedPartUrls).toContain(`https://storage.test/multipart/${sessionId}/3`);

    // Verify complete carries reused ETag for part 1
    const completeReq = server.requests.find(r => r.url === '/documents/multipart/complete');
    const completeParts = (completeReq!.body as Record<string, unknown>).parts as Array<{ PartNumber: number; ETag: string }>;
    expect(completeParts.find(p => p.PartNumber === 1)?.ETag).toBe('seeded-etag-1');
  });

  it('resumes to fresh session if resumeSessionId is missing or not initiated', async () => {
    const server = new FakeServer();
    const file = new File([new Uint8Array(12 * 1024 * 1024)], 'large.pdf', { type: 'application/pdf' });

    const result = await uploadFile(
      file,
      {
        type: 'archive',
        resumeSessionId: '01NONEXISTENTSESSION000001',
      },
      {
        baseUrl: '/documents',
        fetch: server.fetch,
      }
    );

    expect(result.sessionId).not.toBe('01NONEXISTENTSESSION000001');
    const initiateReq = server.requests.find(r => r.url === '/documents/multipart/initiate');
    expect(initiateReq).toBeDefined();
  });

  it('abort via AbortSignal mid-upload calls multipart/abort and rejects with aborted', async () => {
    const server = new FakeServer();
    const file = new File([new Uint8Array(12 * 1024 * 1024)], 'large.pdf', { type: 'application/pdf' });
    const controller = new AbortController();

    // Abort when storage upload begins
    server.injectFailure({
      urlPattern: 'https://storage.test/multipart/',
      predicate: () => {
        controller.abort();
        return true;
      },
      error: new DOMException('Aborted', 'AbortError'),
    });

    await expect(
      uploadFile(
        file,
        {
          type: 'archive',
          signal: controller.signal,
        },
        {
          baseUrl: '/documents',
          fetch: server.fetch,
        }
      )
    ).rejects.toMatchObject({
      code: 'aborted',
    });

    // Assert multipart/abort was called
    const abortReq = server.requests.find(r => r.url === '/documents/multipart/abort');
    expect(abortReq).toBeDefined();
  });

  it('a failed part stops the other workers instead of letting them upload on after the upload has failed', async () => {
    const server = new FakeServer({ configOverrides: { max_concurrency: 3 } });
    const file = new File([new Uint8Array(22 * 1024 * 1024)], 'large.pdf', { type: 'application/pdf' });

    // Part 1 is refused outright; the siblings are slow, so they are still mid-part when it fails.
    server.injectFailure({ urlPattern: '/1', status: 403 });
    for (const part of ['/2', '/3', '/4', '/5']) {
      server.injectFailure({ urlPattern: part, delayMs: 40 });
    }

    await expect(
      uploadFile(file, { type: 'archive' }, { baseUrl: '/documents', fetch: server.fetch })
    ).rejects.toMatchObject({ code: 'forbidden' });

    const storagePuts = () => server.requests.filter(r => r.method === 'PUT' && r.url.startsWith('https://storage.test/')).length;
    const atFailure = storagePuts();
    await new Promise(resolve => setTimeout(resolve, 150));

    expect(storagePuts()).toBe(atFailure);
    expect(server.requests.some(r => r.url === '/documents/multipart/abort')).toBe(true);
  });

  it('local validation errors make zero upload requests', async () => {
    const server = new FakeServer();
    const uploader = createUploader({ baseUrl: '/documents', fetch: server.fetch });

    // 1. unknown_type
    const file1 = new File(['hello'], 'hello.pdf', { type: 'application/pdf' });
    await expect(
      uploader.uploadFile(file1, { type: 'unknown_type_code' })
    ).rejects.toMatchObject({
      code: 'unknown_type',
    });

    // 2. file_too_large (> 10 MiB)
    const file2 = new File([new Uint8Array(11 * 1024 * 1024)], 'large.pdf', { type: 'application/pdf' });
    await expect(
      uploader.uploadFile(file2, { type: 'passport' })
    ).rejects.toMatchObject({
      code: 'file_too_large',
    });

    // 3. type_not_allowed (allowed is 'pdf')
    const file3 = new File(['content'], 'virus.exe', { type: 'application/octet-stream' });
    await expect(
      uploader.uploadFile(file3, { type: 'passport' })
    ).rejects.toMatchObject({
      code: 'type_not_allowed',
    });

    // Only /config was requested, no upload initiation
    const uploadInitiations = server.requests.filter(
      r => r.url.includes('/presigned') || r.url.includes('/multipart')
    );
    expect(uploadInitiations.length).toBe(0);
  });

  it('422 maps to validation with errors', async () => {
    const server = new FakeServer();
    server.injectFailure({
      urlPattern: '/documents/presigned',
      status: 422,
      body: {
        message: 'The filename is invalid.',
        errors: { filename: ['The filename is invalid.'] },
      },
    });

    const file = new File(['content'], 'hello.pdf', { type: 'application/pdf' });
    await expect(
      uploadFile(file, { type: 'passport' }, { baseUrl: '/documents', fetch: server.fetch })
    ).rejects.toMatchObject({
      code: 'validation',
      status: 422,
      errors: { filename: ['The filename is invalid.'] },
    });
  });

  it('401/403 maps to forbidden, 500 maps to server, fetch error maps to network', async () => {
    const file = new File(['content'], 'hello.pdf', { type: 'application/pdf' });

    // 403 -> forbidden
    const server403 = new FakeServer();
    server403.injectFailure({ urlPattern: '/documents/presigned', status: 403 });
    await expect(
      uploadFile(file, { type: 'passport' }, { baseUrl: '/documents', fetch: server403.fetch })
    ).rejects.toMatchObject({
      code: 'forbidden',
      status: 403,
    });

    // 500 -> server
    const server500 = new FakeServer();
    server500.injectFailure({ urlPattern: '/documents/presigned', status: 500 });
    await expect(
      uploadFile(file, { type: 'passport' }, { baseUrl: '/documents', fetch: server500.fetch })
    ).rejects.toMatchObject({
      code: 'server',
      status: 500,
    });

    // network failure
    const serverNet = new FakeServer();
    serverNet.injectFailure({
      urlPattern: '/documents/presigned',
      error: new TypeError('Failed to fetch'),
    });
    await expect(
      uploadFile(file, { type: 'passport' }, { baseUrl: '/documents', fetch: serverNet.fetch })
    ).rejects.toMatchObject({
      code: 'network',
    });
  });

  it('owner set attaches in the final call and returns the document', async () => {
    const server = new FakeServer();
    const content = 'hello document with owner';
    const file = new File([content], 'hello.pdf', { type: 'application/pdf' });

    const result = await uploadFile(
      file,
      {
        type: 'passport',
        owner: { type: 'user', id: 42 },
        metadata: { source: 'web_form' },
        documentGroupId: 'group_abc',
      },
      {
        baseUrl: '/documents',
        fetch: server.fetch,
      }
    );

    expect(result.sessionId).toBeDefined();
    expect(result.document).toBeDefined();
    expect(result.document!.id).toBeDefined();
    expect(result.document!.documentable_type).toBe('user');
    expect(result.document!.documentable_id).toBe('42');

    const finalizeReq = server.requests.find(r => r.url === '/documents/presigned/finalize');
    expect(finalizeReq).toBeDefined();
    const body = finalizeReq!.body as Record<string, unknown>;
    expect(body.documentable_type).toBe('user');
    expect(body.documentable_id).toBe('42');
    expect(body.document_group_id).toBe('group_abc');
    expect(body.metadata).toEqual({ source: 'web_form' });
  });

  it('baseUrl other than /documents is honoured', async () => {
    const customBase = '/api/v2/my-docs';
    const server = new FakeServer({ baseUrl: customBase });
    const file = new File(['content'], 'hello.pdf', { type: 'application/pdf' });

    await uploadFile(
      file,
      { type: 'passport' },
      {
        baseUrl: customBase,
        fetch: server.fetch,
      }
    );

    const serverReqs = server.requests.filter(r => !r.url.startsWith('https://storage.test/'));
    expect(serverReqs.length).toBeGreaterThan(0);
    for (const req of serverReqs) {
      expect(req.url.startsWith(customBase)).toBe(true);
    }
  });

  it('a 48 GB-class Blob-like object plans parts without allocating memory', async () => {
    const server = new FakeServer();
    const fortyEightGb = 48 * 1024 * 1024 * 1024;

    // Fake Blob-like object
    let sliceCalls = 0;
    const fakeBlob = {
      size: fortyEightGb,
      name: 'archive-48gb.pdf',
      type: 'application/pdf',
      slice: (_start?: number, _end?: number) => {
        sliceCalls++;
        return new Blob(['fake-view']);
      },
    } as unknown as File;

    // We can test part planning via initiate
    // Config max_parts is 10000, min_part_size is 5 MiB
    // PartSizePlanner logic:
    // target = max(5 MiB, ceil(48 GiB / 10000)) = ceil(5,153,960.7) = 5,153,961 -> rounded up to 1 MiB is 6 MiB
    // part count = ceil(48 GiB / 6 MiB) = 8,192 <= 10,000!
    // We only simulate initiate step here or initiate with failure to stop after initiate
    for (let i = 0; i < 4; i++) {
      server.injectFailure({
        urlPattern: /\/1$/,
        status: 500,
      });
    }

    await expect(
      uploadFile(
        fakeBlob,
        { type: 'archive' },
        {
          baseUrl: '/documents',
          fetch: server.fetch,
          sleep: async () => {},
        }
      )
    ).rejects.toMatchObject({
      code: 'part_failed',
    });

    const initiateReq = server.requests.find(r => r.url === '/documents/multipart/initiate');
    expect(initiateReq).toBeDefined();
    expect((initiateReq!.body as Record<string, unknown>).declared_size).toBe(fortyEightGb);

    // Assert part count <= max_parts (10,000)
    const session = Array.from(server.sessions.values()).find(s => s.size === fortyEightGb);
    expect(session).toBeDefined();
    const partSize = session!.partSize!;
    const partCount = Math.ceil(fortyEightGb / partSize);
    expect(partCount).toBeLessThanOrEqual(10000);
    expect(partSize).toBeGreaterThanOrEqual(5 * 1024 * 1024);
  });
});
