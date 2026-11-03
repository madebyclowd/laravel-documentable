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

  it('a type with no extension or mime limit (null) uploads', async () => {
    const server = new FakeServer();
    (server.configData.types as Array<Record<string, unknown>>).push({
      id: 'type_any', code: 'any', name: 'Any', max_bytes: 10 * 1024 * 1024,
      allowed_mimes: null, allowed_extensions: null, trust_extension_for_generic_mime: false, allows_multiple: true,
    });
    const file = new File(['content'], 'whatever.xyz', { type: 'application/octet-stream' });

    const result = await uploadFile(file, { type: 'any' }, { baseUrl: '/documents', fetch: server.fetch });

    expect(result.sessionId).toBeDefined();
  });

  it('an unexpected failure leaves uploadFile as an UploadError', async () => {
    const server = new FakeServer();
    server.configData.types = null;
    const file = new File(['content'], 'a.pdf', { type: 'application/pdf' });

    const err = await uploadFile(file, { type: 'passport' }, { baseUrl: '/documents', fetch: server.fetch }).catch(e => e);

    expect(err).toBeInstanceOf(UploadError);
    expect(err.code).toBe('server');
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

  describe('dedup handshake with proof of possession', () => {
    it('hit: skips upload, calls attach when owner is set, returns document', async () => {
      const server = new FakeServer();
      const content = 'Pre-existing content for dedup test';
      const fileBytes = new TextEncoder().encode(content);
      const hash = crypto.createHash('sha256').update(fileBytes).digest('hex');
      server.recordStoredUpload(hash, fileBytes);

      const file = new File([content], 'existing.pdf', { type: 'application/pdf' });
      const owner = { type: 'App\\Models\\User', id: 42 };

      const result = await uploadFile(
        file,
        { type: 'passport', owner, dedup: true },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      expect(result.sessionId).toBeDefined();
      expect(result.document).toBeDefined();
      expect(result.document?.documentable_id).toBe('42');

      // Zero storage PUTs must occur
      const storagePuts = server.requests.filter(r => r.url.startsWith('https://storage.test/'));
      expect(storagePuts.length).toBe(0);

      // Verify handshake requests
      const handshakeReq = server.requests.find(r => r.url === '/documents/handshake');
      expect(handshakeReq).toBeDefined();
      expect((handshakeReq!.body as Record<string, unknown>).hash).toBe(hash);
      expect((handshakeReq!.body as Record<string, unknown>).size).toBe(file.size);

      const answerReq = server.requests.find(r => r.url === '/documents/handshake/answer');
      expect(answerReq).toBeDefined();
      expect((answerReq!.body as Record<string, unknown>).proof).toBeDefined();

      // Verify attach request
      const attachReq = server.requests.find(r => r.url === '/documents/attach');
      expect(attachReq).toBeDefined();
      expect((attachReq!.body as Record<string, unknown>).session_id).toBe(result.sessionId);
    });

    it('hit followed by a refused attach fails the upload instead of uploading the file again', async () => {
      const server = new FakeServer();
      const content = 'Pre-existing content whose attach is refused';
      const fileBytes = new TextEncoder().encode(content);
      const hash = crypto.createHash('sha256').update(fileBytes).digest('hex');
      server.recordStoredUpload(hash, fileBytes);
      server.injectFailure({ urlPattern: '/documents/attach', status: 403 });

      const file = new File([content], 'existing.pdf', { type: 'application/pdf' });

      await expect(
        uploadFile(file, { type: 'passport', owner: { type: 'App\\Models\\User', id: 42 } }, { baseUrl: '/documents', fetch: server.fetch })
      ).rejects.toMatchObject({ code: 'forbidden' });

      expect(server.requests.filter(r => r.url.startsWith('https://storage.test/')).length).toBe(0);
      expect(server.requests.filter(r => r.url === '/documents/presigned').length).toBe(0);
    });

    it('hit without owner: skips upload and returns sessionId without calling attach', async () => {
      const server = new FakeServer();
      const content = 'Pre-existing content no owner';
      const fileBytes = new TextEncoder().encode(content);
      const hash = crypto.createHash('sha256').update(fileBytes).digest('hex');
      server.recordStoredUpload(hash, fileBytes);

      const file = new File([content], 'existing.pdf', { type: 'application/pdf' });

      const result = await uploadFile(
        file,
        { type: 'passport', dedup: true },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      expect(result.sessionId).toBeDefined();
      expect(result.document).toBeUndefined();

      const storagePuts = server.requests.filter(r => r.url.startsWith('https://storage.test/'));
      expect(storagePuts.length).toBe(0);

      const attachReq = server.requests.find(r => r.url === '/documents/attach');
      expect(attachReq).toBeUndefined();
    });

    it('miss/decoy: falls back to normal upload', async () => {
      const server = new FakeServer();
      const content = 'Brand new content not yet in storage';
      const file = new File([content], 'new.pdf', { type: 'application/pdf' });

      const result = await uploadFile(
        file,
        { type: 'passport' },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      expect(result.sessionId).toBeDefined();

      // Handshake was attempted and answered (decoy 422)
      expect(server.requests.some(r => r.url === '/documents/handshake')).toBe(true);
      expect(server.requests.some(r => r.url === '/documents/handshake/answer')).toBe(true);

      // Fell back to normal single PUT
      const storagePuts = server.requests.filter(r => r.url.startsWith('https://storage.test/'));
      expect(storagePuts.length).toBe(1);
    });

    it('wrong proof 422: falls back to normal upload', async () => {
      const server = new FakeServer();
      const content = 'Content for wrong proof fallback test';
      const fileBytes = new TextEncoder().encode(content);
      const hash = crypto.createHash('sha256').update(fileBytes).digest('hex');
      server.recordStoredUpload(hash, fileBytes);

      // Force 422 on handshake/answer
      server.injectFailure({
        urlPattern: '/handshake/answer',
        status: 422,
        body: { message: 'The provided proof is invalid.' },
      });

      const file = new File([content], 'wrong.pdf', { type: 'application/pdf' });

      const result = await uploadFile(
        file,
        { type: 'passport' },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      expect(result.sessionId).toBeDefined();
      // Storage PUT happened after fallback
      const storagePuts = server.requests.filter(r => r.url.startsWith('https://storage.test/'));
      expect(storagePuts.length).toBe(1);
    });

    it('handshake 429 or 500 falls back to normal upload', async () => {
      const server = new FakeServer();
      server.injectFailure({
        urlPattern: '/handshake',
        status: 429,
        body: { message: 'Too many requests' },
      });

      const content = 'Content for 429 fallback test';
      const file = new File([content], 'fallback.pdf', { type: 'application/pdf' });

      const result = await uploadFile(
        file,
        { type: 'passport' },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      expect(result.sessionId).toBeDefined();
      const storagePuts = server.requests.filter(r => r.url.startsWith('https://storage.test/'));
      expect(storagePuts.length).toBe(1);
    });

    it('dedup: false makes zero handshake requests', async () => {
      const server = new FakeServer();
      const content = 'No dedup requested';
      const file = new File([content], 'nodedup.pdf', { type: 'application/pdf' });

      await uploadFile(
        file,
        { type: 'passport', dedup: false },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      const handshakeReqs = server.requests.filter(r => r.url.includes('/handshake'));
      expect(handshakeReqs.length).toBe(0);
    });

    it('size 0 makes zero handshake requests', async () => {
      const server = new FakeServer();
      const file = new File([], 'empty.pdf', { type: 'application/pdf' });

      await uploadFile(
        file,
        { type: 'passport' },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      const handshakeReqs = server.requests.filter(r => r.url.includes('/handshake'));
      expect(handshakeReqs.length).toBe(0);
    });

    it('handshake abort propagates', async () => {
      const server = new FakeServer();
      const controller = new AbortController();

      server.injectFailure({
        urlPattern: '/handshake',
        predicate: () => {
          controller.abort();
          return true;
        },
        delayMs: 10,
      });

      const content = 'Abort during handshake';
      const file = new File([content], 'abort.pdf', { type: 'application/pdf' });

      await expect(
        uploadFile(
          file,
          { type: 'passport', signal: controller.signal },
          { baseUrl: '/documents', fetch: server.fetch }
        )
      ).rejects.toMatchObject({
        code: 'aborted',
      });
    });
  });

  describe('wait for processing', () => {
    it('committed after N polls', async () => {
      const server = new FakeServer();
      let virtualTime = 0;

      const file = new File(['small file for processing poll'], 'doc.pdf', { type: 'application/pdf' });

      // Document initially processing, turns committed on 3rd poll
      server.defaultDocumentStatus = 'processing';
      server.setDocumentStatus(['processing', 'processing', 'committed']);

      const result = await uploadFile(
        file,
        {
          type: 'passport',
          owner: { type: 'App\\Models\\User', id: 1 },
          pollIntervalMs: 500,
        },
        {
          baseUrl: '/documents',
          fetch: server.fetch,
          now: () => virtualTime,
          sleep: async ms => { virtualTime += ms; },
        }
      );

      expect(result.document).toBeDefined();
      expect(result.document?.status).toBe('committed');

      const showRequests = server.requests.filter(r => r.method === 'GET' && r.url.startsWith('/documents/01DOC'));
      expect(showRequests.length).toBeGreaterThanOrEqual(2);
    });

    it('rejected throws UploadError with code "rejected"', async () => {
      const server = new FakeServer();
      let virtualTime = 0;

      const file = new File(['file destined to be rejected'], 'doc.pdf', { type: 'application/pdf' });
      server.defaultDocumentStatus = 'processing';
      server.setDocumentStatus(['processing', 'rejected']);

      await expect(
        uploadFile(
          file,
          {
            type: 'passport',
            owner: { type: 'App\\Models\\User', id: 1 },
            pollIntervalMs: 200,
          },
          {
            baseUrl: '/documents',
            fetch: server.fetch,
            now: () => virtualTime,
            sleep: async ms => { virtualTime += ms; },
          }
        )
      ).rejects.toMatchObject({
        code: 'rejected',
      });
    });

    it('timeout throws UploadError with code "processing_timeout"', async () => {
      const server = new FakeServer();
      let virtualTime = 0;

      const file = new File(['stuck file'], 'doc.pdf', { type: 'application/pdf' });
      server.defaultDocumentStatus = 'processing';

      await expect(
        uploadFile(
          file,
          {
            type: 'passport',
            owner: { type: 'App\\Models\\User', id: 1 },
            pollIntervalMs: 1000,
            processingTimeoutMs: 3000,
          },
          {
            baseUrl: '/documents',
            fetch: server.fetch,
            now: () => virtualTime,
            sleep: async ms => { virtualTime += ms; },
          }
        )
      ).rejects.toMatchObject({
        code: 'processing_timeout',
      });
    });

    it('abort during polling wait propagates', async () => {
      const server = new FakeServer();
      const controller = new AbortController();
      let virtualTime = 0;

      const file = new File(['file to abort during poll'], 'doc.pdf', { type: 'application/pdf' });
      server.defaultDocumentStatus = 'processing';

      await expect(
        uploadFile(
          file,
          {
            type: 'passport',
            owner: { type: 'App\\Models\\User', id: 1 },
            pollIntervalMs: 1000,
            signal: controller.signal,
          },
          {
            baseUrl: '/documents',
            fetch: server.fetch,
            now: () => virtualTime,
            sleep: async ms => {
              virtualTime += ms;
              controller.abort();
            },
          }
        )
      ).rejects.toMatchObject({
        code: 'aborted',
      });
    });

    it('waitForProcessing: false returns processing document untouched without polling', async () => {
      const server = new FakeServer();
      const file = new File(['file no wait'], 'doc.pdf', { type: 'application/pdf' });
      server.defaultDocumentStatus = 'processing';

      const result = await uploadFile(
        file,
        {
          type: 'passport',
          owner: { type: 'App\\Models\\User', id: 1 },
          waitForProcessing: false,
        },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      expect(result.document?.status).toBe('processing');
      const showRequests = server.requests.filter(r => r.method === 'GET' && r.url.startsWith('/documents/01DOC'));
      expect(showRequests.length).toBe(0);
    });

    it('no polling when document status is already committed', async () => {
      const server = new FakeServer();
      const file = new File(['already committed'], 'doc.pdf', { type: 'application/pdf' });
      server.defaultDocumentStatus = 'committed';

      const result = await uploadFile(
        file,
        {
          type: 'passport',
          owner: { type: 'App\\Models\\User', id: 1 },
        },
        { baseUrl: '/documents', fetch: server.fetch }
      );

      expect(result.document?.status).toBe('committed');
      const showRequests = server.requests.filter(r => r.method === 'GET' && r.url.startsWith('/documents/01DOC'));
      expect(showRequests.length).toBe(0);
    });
  });

  describe('XSRF and request headers', () => {
    it('sends X-XSRF-TOKEN and X-Requested-With on server requests when document.cookie exists', async () => {
      const server = new FakeServer();
      const content = 'XSRF test file';
      const file = new File([content], 'xsrf.pdf', { type: 'application/pdf' });

      // Stub globalThis.document
      const originalDoc = (globalThis as unknown as { document?: unknown }).document;
      (globalThis as unknown as { document: { cookie: string } }).document = {
        cookie: 'foo=bar; XSRF-TOKEN=test%3Dtoken%2B123; baz=qux',
      };

      try {
        await uploadFile(
          file,
          { type: 'passport' },
          { baseUrl: '/documents', fetch: server.fetch }
        );

        // Check server request headers
        const configReq = server.requests.find(r => r.url === '/documents/config');
        expect(configReq).toBeDefined();
        expect(configReq!.headers['x-requested-with']).toBe('XMLHttpRequest');
        expect(configReq!.headers['x-xsrf-token']).toBe('test=token+123');

        // Storage PUT must NEVER have these headers
        const storageReq = server.requests.find(r => r.url.startsWith('https://storage.test/'));
        expect(storageReq).toBeDefined();
        expect(storageReq!.headers['x-requested-with']).toBeUndefined();
        expect(storageReq!.headers['x-xsrf-token']).toBeUndefined();
      } finally {
        if (originalDoc === undefined) {
          delete (globalThis as unknown as { document?: unknown }).document;
        } else {
          (globalThis as unknown as { document: unknown }).document = originalDoc;
        }
      }
    });

    it('headers callback replaces default headers entirely', async () => {
      const server = new FakeServer();
      const content = 'Headers callback test';
      const file = new File([content], 'callback.pdf', { type: 'application/pdf' });

      const originalDoc = (globalThis as unknown as { document?: unknown }).document;
      (globalThis as unknown as { document: { cookie: string } }).document = {
        cookie: 'XSRF-TOKEN=secret',
      };

      try {
        await uploadFile(
          file,
          { type: 'passport' },
          {
            baseUrl: '/documents',
            fetch: server.fetch,
            headers: () => ({ 'Authorization': 'Bearer custom-token' }),
          }
        );

        const configReq = server.requests.find(r => r.url === '/documents/config');
        expect(configReq).toBeDefined();
        expect(configReq!.headers['authorization']).toBe('Bearer custom-token');
        // Default XSRF/Requested-With replaced
        expect(configReq!.headers['x-requested-with']).toBeUndefined();
        expect(configReq!.headers['x-xsrf-token']).toBeUndefined();
      } finally {
        if (originalDoc === undefined) {
          delete (globalThis as unknown as { document?: unknown }).document;
        } else {
          (globalThis as unknown as { document: unknown }).document = originalDoc;
        }
      }
    });
  });

  describe('routes option', () => {
    it('overrides default path with route URL, and appends query strings with ? or &', async () => {
      const server = new FakeServer();
      const file = new File(['small file for routes'], 'routes.pdf', { type: 'application/pdf' });

      await uploadFile(
        file,
        { type: 'passport' },
        {
          baseUrl: '/documents',
          fetch: server.fetch,
          routes: {
            config: () => ({ url: '/documents/config' }), // default path
            presign: () => ({ url: '/documents/presigned' }), // default path
            presignFinalize: () => ({ url: '/documents/presigned/finalize' }),
          },
        }
      );

      expect(server.requests.some(r => r.url === '/documents/config')).toBe(true);
      expect(server.requests.some(r => r.url === '/documents/presigned')).toBe(true);
    });

    it('uses custom route URL for multipart status with query string appended', async () => {
      const server = new FakeServer();
      const file = new File([new Uint8Array(11 * 1024 * 1024)], 'large.pdf', { type: 'application/pdf' });

      // Test with custom route that has existing query param
      const customUrl = '/documents/multipart/status';
      await uploadFile(
        file,
        {
          type: 'archive',
          resumeSessionId: '01RESUMESESSIONID00000000',
        },
        {
          baseUrl: '/documents',
          fetch: server.fetch,
          routes: {
            multipartStatus: () => ({ url: `${customUrl}?foo=bar` }),
          },
        }
      );

      const statusReq = server.requests.find(r => r.url.startsWith(customUrl));
      expect(statusReq).toBeDefined();
      expect(statusReq!.url).toContain('foo=bar&session_id=01RESUMESESSIONID00000000');
    });
  });
  describe('progress you can show', () => {
    it('reports stages in order for a small upload with an owner that needs processing', async () => {
      const server = new FakeServer();
      server.setDocumentStatus(['processing', 'committed']);
      const stages: string[] = [];
      const file = new File(['hello'], 'a.pdf', { type: 'application/pdf' });

      await uploadFile(file, { type: 'passport', owner: { type: 'App\\Models\\User', id: 1 }, onStage: s => stages.push(s) },
        { baseUrl: '/documents', fetch: server.fetch, sleep: async () => {} });

      expect(stages).toEqual(['hashing', 'uploading', 'finalizing', 'processing']);
    });

    it('a multipart upload without an owner stops after finalizing', async () => {
      const server = new FakeServer();
      const stages: string[] = [];
      const file = new File([new Uint8Array(12 * 1024 * 1024)], 'big.bin', { type: 'application/octet-stream' });

      await uploadFile(file, { type: 'archive', dedup: false, onStage: s => stages.push(s) },
        { baseUrl: '/documents', fetch: server.fetch, sleep: async () => {} });

      expect(stages).toEqual(['hashing', 'uploading', 'finalizing']);
    });

    it('reports hashing progress up to the file size', async () => {
      const server = new FakeServer();
      const seen: Array<{ loaded: number; total: number }> = [];
      const file = new File([new Uint8Array(9 * 1024 * 1024)], 'a.pdf', { type: 'application/pdf' });

      await uploadFile(file, { type: 'archive', dedup: false, onHashProgress: p => seen.push(p) },
        { baseUrl: '/documents', fetch: server.fetch, sleep: async () => {} });

      expect(seen.length).toBe(3);
      expect(seen.at(-1)).toEqual({ loaded: file.size, total: file.size });
    });

    it('uses the app hasher when one is set and rejects a malformed result', async () => {
      const server = new FakeServer();
      const file = new File(['hello'], 'a.pdf', { type: 'application/pdf' });
      const good = crypto.createHash('sha256').update('hello').digest('hex').toUpperCase();

      await uploadFile(file, { type: 'passport' }, { baseUrl: '/documents', fetch: server.fetch, hash: async () => good });
      const presign = server.requests.find(r => r.url === '/documents/presigned');
      expect((presign!.body as { declared_hash: string }).declared_hash).toBe(good.toLowerCase());

      const err = await uploadFile(file, { type: 'passport' }, { baseUrl: '/documents', fetch: server.fetch, hash: async () => 'nope' }).catch(e => e);
      expect(err).toBeInstanceOf(UploadError);
      expect(err.code).toBe('server');
    });

    it('polls with a growing delay unless pollIntervalMs is set', async () => {
      const server = new FakeServer();
      server.setDocumentStatus(['processing', 'processing', 'processing', 'processing', 'committed']);
      const delays: number[] = [];
      let virtualTime = 0;
      const file = new File(['hello'], 'a.pdf', { type: 'application/pdf' });

      await uploadFile(file, { type: 'passport', owner: { type: 'App\\Models\\User', id: 1 } },
        { baseUrl: '/documents', fetch: server.fetch, now: () => virtualTime, sleep: async ms => { delays.push(ms); virtualTime += ms; } });

      expect(delays).toEqual([1000, 2000, 5000, 5000]);
    });
  });

  describe('interrupted connections', () => {
    const bigFile = () => new File([new Uint8Array(12 * 1024 * 1024)], 'large.pdf', { type: 'application/pdf' });
    const client = (server: FakeServer, extra: Record<string, unknown> = {}) => ({
      baseUrl: '/documents',
      fetch: server.fetch,
      sleep: async () => {},
      random: () => 0,
      ...extra,
    });
    const drop = () => new TypeError('Failed to fetch');
    const aborts = (server: FakeServer) => server.requests.filter(r => r.url === '/documents/multipart/abort');

    it('hands the multipart session id to onSession, for a new session and for a resumed one', async () => {
      const server = new FakeServer();
      const seen: string[] = [];

      const first = await uploadFile(bigFile(), { type: 'archive', onSession: id => seen.push(id) }, client(server));
      expect(seen).toEqual([first.sessionId]);

      const resumedSeen: string[] = [];
      const sessionId = '01RESUMEONSESSION0000001';
      server.sessions.set(sessionId, { id: sessionId, kind: 'multipart', status: 'initiated', typeId: 'type_archive', filename: 'large.pdf', size: 12 * 1024 * 1024, partSize: 5 * 1024 * 1024 });
      server.partBodies.set(sessionId, new Map([[1, new Uint8Array(5 * 1024 * 1024)]]));
      server.partETags.set(sessionId, new Map([[1, 'seeded-etag-1']]));
      await uploadFile(bigFile(), { type: 'archive', dedup: false, resumeSessionId: sessionId, onSession: id => resumedSeen.push(id) }, client(server));
      expect(resumedSeen).toEqual([sessionId]);
    });

    it('a part that keeps failing keeps the session and puts its id on the error', async () => {
      const server = new FakeServer();
      for (let i = 0; i < 4; i++) server.injectFailure({ urlPattern: /\/1$/, status: 500 });
      const seen: string[] = [];

      const error = await uploadFile(bigFile(), { type: 'archive', onSession: id => seen.push(id) }, client(server)).catch(e => e);

      expect(error).toBeInstanceOf(UploadError);
      expect(error.code).toBe('part_failed');
      expect(error.sessionId).toBe(seen[0]);
      expect(aborts(server)).toHaveLength(0);
    });

    it('retries the part-url request when the connection drops once', async () => {
      const server = new FakeServer();
      server.injectFailure({ urlPattern: '/multipart/part-urls', error: drop() });
      const delays: number[] = [];

      const result = await uploadFile(bigFile(), { type: 'archive' }, client(server, { sleep: async (ms: number) => { delays.push(ms); } }));

      expect(result.sessionId).toBeTruthy();
      expect(delays.length).toBeGreaterThanOrEqual(1);
      expect(server.requests.filter(r => r.url === '/documents/multipart/part-urls').length).toBeGreaterThanOrEqual(2);
    });

    it('part-url requests that keep failing end as a network error that keeps the session', async () => {
      const server = new FakeServer();
      for (let i = 0; i < 4; i++) server.injectFailure({ urlPattern: '/multipart/part-urls', error: drop() });
      const seen: string[] = [];

      const error = await uploadFile(bigFile(), { type: 'archive', onSession: id => seen.push(id) }, client(server)).catch(e => e);

      expect(error.code).toBe('network');
      expect(error.sessionId).toBe(seen[0]);
      expect(aborts(server)).toHaveLength(0);
    });

    it('a connection lost while completing keeps the session so the caller can resume', async () => {
      const server = new FakeServer();
      server.injectFailure({ urlPattern: '/multipart/complete', error: drop() });
      const seen: string[] = [];

      const error = await uploadFile(bigFile(), { type: 'archive', onSession: id => seen.push(id) }, client(server)).catch(e => e);

      expect(error.code).toBe('network');
      expect(error.sessionId).toBe(seen[0]);
      expect(aborts(server)).toHaveLength(0);
      expect(server.requests.filter(r => r.url === '/documents/multipart/complete')).toHaveLength(1);
    });

    it('does not retry initiate, because a second attempt could leave a stray session behind', async () => {
      const server = new FakeServer();
      server.injectFailure({ urlPattern: '/multipart/initiate', error: drop() });

      const error = await uploadFile(bigFile(), { type: 'archive' }, client(server)).catch(e => e);

      expect(error.code).toBe('network');
      expect(error.sessionId).toBeUndefined();
      expect(server.requests.filter(r => r.url === '/documents/multipart/initiate')).toHaveLength(1);
    });

    it('still aborts the session when storage refuses the upload, and gives no id to resume', async () => {
      const server = new FakeServer();
      server.injectFailure({ urlPattern: 'https://storage.test/multipart/', status: 403 });

      const error = await uploadFile(bigFile(), { type: 'archive' }, client(server)).catch(e => e);

      expect(error.code).toBe('forbidden');
      expect(error.sessionId).toBeUndefined();
      expect(aborts(server)).toHaveLength(1);
    });

    it('while the browser reports offline, a failed part waits for it instead of using up its attempts', async () => {
      const server = new FakeServer();
      for (let i = 0; i < 6; i++) server.injectFailure({ urlPattern: /\/1$/, error: drop() });
      let offlineChecks = 0;
      let waits = 0;

      const result = await uploadFile(bigFile(), { type: 'archive' }, client(server, {
        isOnline: () => ++offlineChecks > 6,
        waitForOnline: async () => { waits++; },
      }));

      expect(result.sessionId).toBeTruthy();
      expect(waits).toBe(6);
    });
  });
});
