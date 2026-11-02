export interface DocumentRecord {
  id: string;
  [key: string]: unknown;
}

export interface UploadOptions {
  type: string;                                   // a document type code or id, resolved from /config
  owner?: { type: string; id: string | number };  // present: attach in the same flow; absent: stop at an uploaded session
  metadata?: Record<string, unknown>;
  documentGroupId?: string;
  onProgress?: (p: { loaded: number; total: number }) => void;
  signal?: AbortSignal;
  resumeSessionId?: string;                       // continue an interrupted multipart upload
}

export interface ClientOptions {
  baseUrl?: string;                               // default '/documents'; matches documentable.route_prefix
  fetch?: typeof fetch;                           // default globalThis.fetch
  headers?: () => Record<string, string> | Promise<Record<string, string>>; // auth, XSRF, etc, added to every server request
  sleep?: (ms: number) => Promise<void>;
  random?: () => number;
}

export interface UploadResult {
  sessionId: string;
  document?: DocumentRecord;
}

export type UploadErrorCode =
  | 'aborted'
  | 'file_too_large'
  | 'type_not_allowed'
  | 'unknown_type'
  | 'network'
  | 'validation'
  | 'forbidden'
  | 'server'
  | 'part_failed';

export class UploadError extends Error {
  readonly code: UploadErrorCode;
  readonly status?: number;
  readonly errors?: Record<string, string[]>;

  constructor(code: UploadErrorCode, message?: string, options?: { status?: number; errors?: Record<string, string[]> }) {
    super(message ?? code);
    this.name = 'UploadError';
    this.code = code;
    this.status = options?.status;
    this.errors = options?.errors;
    Object.setPrototypeOf(this, UploadError.prototype);
  }
}

interface DocumentTypeConfig {
  id: string;
  code: string;
  name: string;
  max_bytes: number;
  allowed_mimes: string[];
  allowed_extensions: string[];
  trust_extension_for_generic_mime: boolean;
  allows_multiple: boolean;
}

interface ClientConfig {
  threshold_bytes: number;
  min_part_size: number;
  max_parts: number;
  max_concurrency: number;
  retry: { attempts: number; base_delay_ms: number; max_delay_ms: number };
  sync_hash_max_bytes: number;
  etag_strategy: string;
  types: DocumentTypeConfig[];
}

/** Pure TypeScript streaming SHA-256 (Web Crypto cannot hash incrementally). */
export class Sha256 {
  private h = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
  private buffer = new Uint8Array(64);
  private bufLen = 0;
  private totalBytes = 0n;
  private finalized = false;
  private w = new Uint32Array(64);

  private static readonly K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
  ]);

  private processBlock(block: Uint8Array): void {
    const w = this.w, K = Sha256.K;
    const view = new DataView(block.buffer, block.byteOffset, 64);
    for (let t = 0; t < 16; t++) w[t] = view.getUint32(t * 4, false);
    for (let t = 16; t < 64; t++) {
      const w15 = w[t - 15]!, w2 = w[t - 2]!;
      const s0 = ((w15 >>> 7) | (w15 << 25)) ^ ((w15 >>> 18) | (w15 << 14)) ^ (w15 >>> 3);
      const s1 = ((w2 >>> 17) | (w2 << 15)) ^ ((w2 >>> 19) | (w2 << 13)) ^ (w2 >>> 10);
      w[t] = (w[t - 16]! + s0 + w[t - 7]! + s1) >>> 0;
    }
    let [a, b, c, d, e, f, g, h] = this.h as unknown as [number, number, number, number, number, number, number, number];
    for (let t = 0; t < 64; t++) {
      const s1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
      const ch = (e & f) ^ (~e & g);
      const t1 = (h + s1 + ch + K[t]! + w[t]!) >>> 0;
      const s0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
      const maj = (a & b) ^ (a & c) ^ (b & c);
      const t2 = (s0 + maj) >>> 0;
      h = g; g = f; f = e; e = (d + t1) >>> 0; d = c; c = b; b = a; a = (t1 + t2) >>> 0;
    }
    const add = [a, b, c, d, e, f, g, h];
    for (let i = 0; i < 8; i++) this.h[i] = (this.h[i]! + add[i]!) >>> 0;
  }

  update(data: Uint8Array | ArrayBuffer | ArrayBufferView | string): this {
    if (this.finalized) throw new Error('Sha256 has already been finalized');
    let bytes: Uint8Array;
    if (typeof data === 'string') bytes = new TextEncoder().encode(data);
    else if (data instanceof Uint8Array) bytes = data;
    else if (data instanceof ArrayBuffer) bytes = new Uint8Array(data);
    else bytes = new Uint8Array(data.buffer, data.byteOffset, data.byteLength);

    this.totalBytes += BigInt(bytes.byteLength);
    let offset = 0, len = bytes.byteLength;
    if (this.bufLen > 0) {
      const needed = 64 - this.bufLen;
      if (len >= needed) {
        this.buffer.set(bytes.subarray(0, needed), this.bufLen);
        this.processBlock(this.buffer);
        offset += needed; len -= needed; this.bufLen = 0;
      } else {
        this.buffer.set(bytes, this.bufLen);
        this.bufLen += len;
        return this;
      }
    }
    while (len >= 64) {
      this.processBlock(bytes.subarray(offset, offset + 64));
      offset += 64; len -= 64;
    }
    if (len > 0) {
      this.buffer.set(bytes.subarray(offset, offset + len), 0);
      this.bufLen = len;
    }
    return this;
  }

  digest(): string {
    if (this.finalized) throw new Error('Sha256 has already been finalized');
    this.finalized = true;
    const totalBits = this.totalBytes * 8n;
    this.buffer[this.bufLen++] = 0x80;
    if (this.bufLen > 56) {
      this.buffer.fill(0, this.bufLen, 64);
      this.processBlock(this.buffer);
      this.bufLen = 0;
    }
    this.buffer.fill(0, this.bufLen, 56);
    new DataView(this.buffer.buffer, this.buffer.byteOffset, 64).setBigUint64(56, totalBits, false);
    this.processBlock(this.buffer);
    return Array.from(this.h).map(n => n.toString(16).padStart(8, '0')).join('');
  }
}

/** Reads a Blob in 4 MiB slices to calculate lower-case hex SHA-256 without memory blowup. */
export async function hashBlob(blob: Blob, signal?: AbortSignal): Promise<string> {
  const CHUNK_SIZE = 4 * 1024 * 1024;
  const sha = new Sha256();
  let offset = 0;
  while (offset < blob.size) {
    if (signal?.aborted) throw new UploadError('aborted', 'Hashing was aborted');
    const end = Math.min(offset + CHUNK_SIZE, blob.size);
    const buffer = await blob.slice(offset, end).arrayBuffer();
    sha.update(buffer);
    offset = end;
  }
  return sha.digest();
}

/** Uniform part sizing matching PartSizePlanner: >= 5 MiB, uniform rounded up to 1 MiB. */
function computePartSize(totalBytes: number, minPartSize = 5 * 1024 * 1024): number {
  const ONE_MIB = 1024 * 1024;
  const target = Math.max(minPartSize, Math.ceil(totalBytes / 10000));
  return Math.ceil(target / ONE_MIB) * ONE_MIB;
}

const defaultSleep = (ms: number) => new Promise<void>(resolve => setTimeout(resolve, ms));

function attachPayload(options: UploadOptions, base: Record<string, unknown>): Record<string, unknown> {
  if (options.owner) {
    base.documentable_type = options.owner.type;
    base.documentable_id = String(options.owner.id);
  }
  if (options.metadata) base.metadata = options.metadata;
  if (options.documentGroupId) base.document_group_id = options.documentGroupId;
  return base;
}

async function requestServer<T>(
  client: ClientOptions,
  path: string,
  method: 'GET' | 'POST',
  body?: unknown,
  signal?: AbortSignal
): Promise<T> {
  const fetchFn = client.fetch ?? globalThis.fetch;
  const baseUrl = (client.baseUrl ?? '/documents').replace(/\/+$/, '');
  const customHeaders = client.headers ? await client.headers() : {};
  const headers: Record<string, string> = { Accept: 'application/json', ...customHeaders };
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  let res: Response;
  try {
    res = await fetchFn(`${baseUrl}${path}`, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      credentials: 'same-origin',
      signal,
    });
  } catch (err) {
    if (signal?.aborted || (err instanceof Error && err.name === 'AbortError')) {
      throw new UploadError('aborted', 'Upload was aborted');
    }
    throw new UploadError('network', err instanceof Error ? err.message : 'Network request failed');
  }

  if (!res.ok) {
    let errorJson: { message?: string; errors?: Record<string, string[]> } | undefined;
    try {
      errorJson = (await res.json()) as { message?: string; errors?: Record<string, string[]> };
    } catch {
      // Body not JSON
    }
    if (res.status === 401 || res.status === 403) {
      throw new UploadError('forbidden', errorJson?.message ?? 'Forbidden', { status: res.status });
    }
    if (res.status === 422) {
      throw new UploadError('validation', errorJson?.message ?? 'Validation failed', {
        status: 422,
        errors: errorJson?.errors,
      });
    }
    throw new UploadError('server', errorJson?.message ?? `Server error (${res.status})`, { status: res.status });
  }

  return res.status === 204 ? (undefined as unknown as T) : ((await res.json()) as T);
}

async function uploadPartWithRetry(
  fetchFn: typeof fetch,
  url: string,
  partSlice: Blob,
  retryPolicy: { attempts: number; base_delay_ms: number; max_delay_ms: number },
  etagStrategy: string,
  signal?: AbortSignal,
  sleepFn = defaultSleep,
  randomFn = Math.random
): Promise<string | undefined> {
  const maxTries = 1 + retryPolicy.attempts;
  let lastStatus: number | undefined;

  for (let attempt = 0; attempt < maxTries; attempt++) {
    if (signal?.aborted) throw new UploadError('aborted', 'Part upload aborted');
    try {
      const res = await fetchFn(url, { method: 'PUT', body: partSlice, signal });
      if (res.ok) {
        if (etagStrategy === 'client') {
          const rawEtag = res.headers.get('etag') ?? res.headers.get('ETag');
          if (!rawEtag) {
            throw new UploadError('part_failed', 'ETag header missing from storage response. Ensure CORS configuration includes ExposeHeaders for ETag.');
          }
          return rawEtag.replace(/^"|"$/g, '').trim();
        }
        return undefined;
      }
      lastStatus = res.status;
      // 4xx other than 429 fails immediately
      if (res.status >= 400 && res.status < 500 && res.status !== 429) {
        const code = (res.status === 401 || res.status === 403) ? 'forbidden' : 'server';
        throw new UploadError(code, `Storage upload rejected (${res.status})`, { status: res.status });
      }
    } catch (err) {
      if (err instanceof UploadError) throw err;
      if (signal?.aborted || (err instanceof Error && err.name === 'AbortError')) {
        throw new UploadError('aborted', 'Part upload aborted');
      }
    }

    if (attempt < maxTries - 1) {
      const delayCap = Math.min(retryPolicy.max_delay_ms, retryPolicy.base_delay_ms * Math.pow(2, attempt));
      await sleepFn(delayCap * randomFn());
    }
  }

  throw new UploadError('part_failed', 'Part upload failed after exhausting retries', { status: lastStatus });
}

export function createUploader(client: ClientOptions = {}): {
  uploadFile(file: File | (Blob & { name: string }), options: UploadOptions): Promise<UploadResult>;
} {
  const fetchFn = client.fetch ?? globalThis.fetch;
  let configPromise: Promise<ClientConfig> | null = null;

  function getConfig(signal?: AbortSignal): Promise<ClientConfig> {
    if (!configPromise) {
      configPromise = requestServer<ClientConfig>(client, '/config', 'GET', undefined, signal).catch(err => {
        configPromise = null;
        throw err;
      });
    }
    return configPromise;
  }

  async function uploadFileImpl(
    file: File | (Blob & { name: string }),
    options: UploadOptions
  ): Promise<UploadResult> {
    const filename = file.name;
    const config = await getConfig(options.signal);
    const docType = config.types.find(t => t.code === options.type) ?? config.types.find(t => t.id === options.type);
    if (!docType) throw new UploadError('unknown_type', `Unknown document type "${options.type}"`);

    if (file.size > docType.max_bytes) {
      throw new UploadError('file_too_large', `File size (${file.size}) exceeds maximum allowed bytes (${docType.max_bytes})`);
    }

    if (docType.allowed_extensions.length > 0) {
      const ext = filename.includes('.') ? filename.split('.').pop()!.toLowerCase() : '';
      if (!docType.allowed_extensions.map(e => e.toLowerCase()).includes(ext)) {
        throw new UploadError('type_not_allowed', `File extension .${ext} is not allowed for type "${docType.name}"`);
      }
    }

    const hash = await hashBlob(file, options.signal);
    return file.size <= config.threshold_bytes
      ? uploadSinglePut(file, options, docType, hash)
      : uploadMultipart(file, options, config, docType, hash);
  }

  async function uploadSinglePut(
    file: File | (Blob & { name: string }),
    options: UploadOptions,
    docType: DocumentTypeConfig,
    hash: string
  ): Promise<UploadResult> {
    const presign = await requestServer<{ session_id: string; url: string; headers: Record<string, string> }>(
      client, '/presigned', 'POST', {
        document_type_id: docType.id,
        filename: file.name,
        declared_size: file.size,
        declared_mime: file.type || null,
        declared_hash: hash,
      }, options.signal
    );

    try {
      const putRes = await fetchFn(presign.url, { method: 'PUT', headers: presign.headers, body: file, signal: options.signal });
      if (!putRes.ok) {
        const code = (putRes.status === 401 || putRes.status === 403) ? 'forbidden' : 'server';
        throw new UploadError(code, `Storage rejected PUT (${putRes.status})`, { status: putRes.status });
      }
    } catch (err) {
      if (err instanceof UploadError) throw err;
      if (options.signal?.aborted || (err instanceof Error && err.name === 'AbortError')) {
        throw new UploadError('aborted', 'Storage upload was aborted');
      }
      throw new UploadError('network', err instanceof Error ? err.message : 'Storage upload failed');
    }

    options.onProgress?.({ loaded: file.size, total: file.size });
    const finalizeBody = attachPayload(options, { session_id: presign.session_id, expected_hash: hash });
    const finalized = await requestServer<Record<string, unknown>>(client, '/presigned/finalize', 'POST', finalizeBody, options.signal);
    return { sessionId: presign.session_id, document: options.owner ? (finalized as DocumentRecord) : undefined };
  }

  async function uploadMultipart(
    file: File | (Blob & { name: string }),
    options: UploadOptions,
    config: ClientConfig,
    docType: DocumentTypeConfig,
    hash: string
  ): Promise<UploadResult> {
    let sessionId: string | null = null;
    let partSize = computePartSize(file.size, config.min_part_size);
    const completedParts: Array<{ PartNumber: number; ETag: string }> = [];
    const completedSet = new Set<number>();

    // 1. Resume check
    if (options.resumeSessionId) {
      try {
        const status = await requestServer<{ exists: boolean; status: string | null }>(
          client, `/multipart/status?session_id=${encodeURIComponent(options.resumeSessionId)}`, 'GET', undefined, options.signal
        );
        if (status.exists && status.status === 'initiated') {
          sessionId = options.resumeSessionId;
          const { parts } = await requestServer<{ parts: Array<{ PartNumber: number; ETag: string }> }>(
            client, `/multipart/parts?session_id=${encodeURIComponent(sessionId)}`, 'GET', undefined, options.signal
          );
          for (const p of parts) {
            completedSet.add(p.PartNumber);
            completedParts.push({ PartNumber: p.PartNumber, ETag: p.ETag.replace(/^"|"$/g, '').trim() });
          }
        }
      } catch {
        sessionId = null;
      }
    }

    try {
      // 2. Initiate fresh session if not resumed
      if (!sessionId) {
        const init = await requestServer<{ id: string; part_size?: number }>(
          client, '/multipart/initiate', 'POST', {
            document_type_id: docType.id,
            filename: file.name,
            declared_size: file.size,
            declared_mime: file.type || null,
            declared_hash: hash,
          }, options.signal
        );
        sessionId = init.id;
        if (init.part_size) partSize = init.part_size;
      }

      const partCount = Math.max(1, Math.ceil(file.size / partSize));
      let loadedBytes = 0;
      for (const p of completedSet) {
        loadedBytes += p === partCount ? file.size - (partCount - 1) * partSize : partSize;
      }
      if (loadedBytes > 0) options.onProgress?.({ loaded: loadedBytes, total: file.size });

      const partsToUpload: number[] = [];
      for (let p = 1; p <= partCount; p++) {
        if (!completedSet.has(p)) partsToUpload.push(p);
      }

      // 3. Parallel part upload with URL batching and bounded concurrency. The workers share an internal signal so
      // that the first failure stops the others, and the caller's signal still stops all of them.
      const inner = new AbortController();
      const stopOnCallerAbort = () => inner.abort();
      if (options.signal?.aborted) inner.abort();
      else options.signal?.addEventListener('abort', stopOnCallerAbort, { once: true });
      const urlCache = new Map<number, string>();
      let pendingBatch: Promise<void> | null = null;

      async function getPartUrl(pNum: number): Promise<string> {
        while (!urlCache.has(pNum)) {
          if (!pendingBatch) {
            const needed = partsToUpload.filter(p => !urlCache.has(p)).slice(0, 100);
            pendingBatch = requestServer<{ urls: Record<string, string> }>(
              client, '/multipart/part-urls', 'POST', { session_id: sessionId, part_numbers: needed }, inner.signal
            ).then(res => {
              for (const [k, v] of Object.entries(res.urls)) urlCache.set(Number(k), v);
              pendingBatch = null;
            }).catch(err => {
              pendingBatch = null;
              throw err;
            });
          }
          await pendingBatch;
        }
        return urlCache.get(pNum)!;
      }

      const concurrency = Math.min(config.max_concurrency || 4, partsToUpload.length);
      let nextIndex = 0;
      const workers: Promise<void>[] = [];
      for (let w = 0; w < concurrency; w++) {
        workers.push((async () => {
          try {
            while (nextIndex < partsToUpload.length) {
              if (inner.signal.aborted) throw new UploadError('aborted', 'Upload was aborted');
              const pNum = partsToUpload[nextIndex++]!;
              const url = await getPartUrl(pNum);
              const start = (pNum - 1) * partSize;
              const end = Math.min(start + partSize, file.size);
              const etag = await uploadPartWithRetry(
                fetchFn, url, file.slice(start, end), config.retry, config.etag_strategy,
                inner.signal, client.sleep, client.random
              );
              if (etag !== undefined) completedParts.push({ PartNumber: pNum, ETag: etag });
              loadedBytes += end - start;
              options.onProgress?.({ loaded: loadedBytes, total: file.size });
            }
          } catch (err) {
            inner.abort();
            throw err;
          }
        })());
      }
      const settled = await Promise.allSettled(workers);
      options.signal?.removeEventListener('abort', stopOnCallerAbort);
      const failures = settled.filter((r): r is PromiseRejectedResult => r.status === 'rejected').map(r => r.reason);
      if (failures.length > 0) {
        // The failure that stopped the others is the real one; the rest are only them reacting to it.
        throw failures.find(f => !(f instanceof UploadError && f.code === 'aborted')) ?? failures[0];
      }

      // 4. Complete session
      const completeBody = attachPayload(options, { session_id: sessionId, expected_hash: hash });
      if (config.etag_strategy === 'client') {
        completedParts.sort((a, b) => a.PartNumber - b.PartNumber);
        completeBody.parts = completedParts;
      }
      const completeRes = await requestServer<Record<string, unknown>>(
        client, '/multipart/complete', 'POST', completeBody, options.signal
      );
      return { sessionId, document: options.owner ? (completeRes as DocumentRecord) : undefined };
    } catch (err) {
      // Best-effort abort on failure; keep session if part_failed so caller can resume.
      if (sessionId && !(err instanceof UploadError && err.code === 'part_failed')) {
        try {
          await requestServer(client, '/multipart/abort', 'POST', { session_id: sessionId });
        } catch {
          // Never mask original failure
        }
      }
      throw err;
    }
  }

  return { uploadFile: uploadFileImpl };
}

export function uploadFile(
  file: File | (Blob & { name: string }),
  options: UploadOptions,
  client?: ClientOptions
): Promise<UploadResult> {
  return createUploader(client).uploadFile(file, options);
}
