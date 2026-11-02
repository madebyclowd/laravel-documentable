import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const fixturesDir = path.resolve(__dirname, '../../resources/client/fixtures');

function loadFixture(filename: string): unknown {
  const content = fs.readFileSync(path.join(fixturesDir, filename), 'utf8');
  return JSON.parse(content);
}

export interface RecordedRequest {
  method: string;
  url: string;
  headers: Record<string, string>;
  body: unknown;
  rawBody?: ArrayBuffer;
}

export interface InjectedFailure {
  predicate?: (req: RecordedRequest) => boolean;
  urlPattern?: RegExp | string;
  status?: number;
  error?: Error;
  delayMs?: number;
  body?: unknown;
  headers?: Record<string, string>;
}

export interface FakeServerOptions {
  baseUrl?: string;
  configOverrides?: Record<string, unknown>;
  etagStrategy?: 'client' | 'server-authoritative';
}

export class FakeServer {
  baseUrl: string;
  requests: RecordedRequest[] = [];
  injectedFailures: InjectedFailure[] = [];

  // Storage for PUT bodies
  singlePutBodies = new Map<string, Uint8Array>();
  partBodies = new Map<string, Map<number, Uint8Array>>();
  partETags = new Map<string, Map<number, string>>();

  // Active concurrency tracking
  activeUploads = 0;
  maxActiveUploads = 0;

  // Sessions state
  sessions = new Map<string, {
    id: string;
    kind: 'presigned' | 'multipart';
    status: string;
    typeId: string;
    filename: string;
    size?: number;
    mime?: string;
    hash?: string;
    partSize?: number;
  }>();

  idCounter = 1;

  configData: Record<string, unknown>;

  constructor(options: FakeServerOptions = {}) {
    this.baseUrl = options.baseUrl ?? '/documents';
    const baseConfig = loadFixture('config.json') as Record<string, unknown>;
    this.configData = {
      ...baseConfig,
      ...options.configOverrides,
    };
    if (options.etagStrategy) {
      this.configData.etag_strategy = options.etagStrategy;
    }
    // Replace <id> in types
    const types = (this.configData.types as Array<Record<string, unknown>>) ?? [];
    for (const t of types) {
      if (t.id === '<id>') {
        t.id = 'type_' + String(t.code ?? 'doc');
      }
    }
    if (!types.some(t => t.code === 'archive')) {
      types.push({
        id: 'type_archive',
        code: 'archive',
        name: 'Archive',
        max_bytes: 100 * 1024 * 1024 * 1024,
        allowed_extensions: ['pdf', 'bin', 'zip'],
        allowed_mimes: ['application/pdf', 'application/octet-stream'],
        trust_extension_for_generic_mime: true,
        allows_multiple: true,
      });
    }
  }

  generateId(prefix = '01ARZ3NDEKTSV4RRFFQ69G5'): string {
    const id = prefix + String(this.idCounter++).padStart(4, '0');
    return id.padEnd(26, '0').slice(0, 26);
  }

  injectFailure(failure: InjectedFailure): void {
    this.injectedFailures.push(failure);
  }

  getReassembledBody(sessionId: string): Uint8Array {
    if (this.singlePutBodies.has(sessionId)) {
      return this.singlePutBodies.get(sessionId)!;
    }
    const partsMap = this.partBodies.get(sessionId);
    if (!partsMap || partsMap.size === 0) {
      return new Uint8Array(0);
    }
    const sortedParts = Array.from(partsMap.entries()).sort((a, b) => a[0] - b[0]);
    const totalLength = sortedParts.reduce((acc, [, buf]) => acc + buf.byteLength, 0);
    const combined = new Uint8Array(totalLength);
    let offset = 0;
    for (const [, buf] of sortedParts) {
      combined.set(buf, offset);
      offset += buf.byteLength;
    }
    return combined;
  }

  fetch: typeof fetch = async (input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
    const rawUrl = typeof input === 'string' ? input : input instanceof URL ? input.toString() : input.url;
    const method = (init?.method ?? 'GET').toUpperCase();
    const headers: Record<string, string> = {};
    if (init?.headers) {
      const h = new Headers(init.headers);
      h.forEach((value, key) => {
        headers[key.toLowerCase()] = value;
      });
    }

    let parsedBody: unknown = undefined;
    let rawBody: ArrayBuffer | undefined = undefined;

    if (init?.body) {
      if (typeof init.body === 'string') {
        try {
          parsedBody = JSON.parse(init.body);
        } catch {
          parsedBody = init.body;
        }
      } else if (init.body instanceof ArrayBuffer) {
        rawBody = init.body;
      } else if (ArrayBuffer.isView(init.body)) {
        rawBody = init.body.buffer.slice(init.body.byteOffset, init.body.byteOffset + init.body.byteLength) as ArrayBuffer;
      } else if (typeof (init.body as Blob).arrayBuffer === 'function') {
        rawBody = await (init.body as Blob).arrayBuffer();
      }
    }

    const recorded: RecordedRequest = {
      method,
      url: rawUrl,
      headers,
      body: parsedBody,
      rawBody,
    };
    this.requests.push(recorded);

    // Check for injected failures
    const failureIndex = this.injectedFailures.findIndex(f => {
      if (f.urlPattern) {
        if (typeof f.urlPattern === 'string' && !rawUrl.includes(f.urlPattern)) return false;
        if (f.urlPattern instanceof RegExp && !f.urlPattern.test(rawUrl)) return false;
      }
      if (f.predicate && !f.predicate(recorded)) return false;
      return true;
    });

    if (failureIndex >= 0) {
      const failure = this.injectedFailures.splice(failureIndex, 1)[0]!;
      if (failure.delayMs) {
        await new Promise(r => setTimeout(r, failure.delayMs));
      }
      if (failure.error) {
        throw failure.error;
      }
      if (failure.status) {
        const bodyStr = failure.body !== undefined
          ? (typeof failure.body === 'string' ? failure.body : JSON.stringify(failure.body))
          : JSON.stringify({ message: 'Injected error' });
        return new Response(bodyStr, {
          status: failure.status,
          headers: { 'Content-Type': 'application/json', ...(failure.headers ?? {}) },
        });
      }
    }

    // Storage PUT requests
    if (rawUrl.startsWith('https://storage.test/')) {
      this.activeUploads++;
      if (this.activeUploads > this.maxActiveUploads) {
        this.maxActiveUploads = this.activeUploads;
      }

      try {
        const urlObj = new URL(rawUrl);
        const pathParts = urlObj.pathname.split('/').filter(Boolean); // ['presigned', sessionId] or ['multipart', sessionId, partNumber]
        if (pathParts[0] === 'presigned') {
          const sessionId = pathParts[1]!;
          if (rawBody) {
            this.singlePutBodies.set(sessionId, new Uint8Array(rawBody));
          }
          return new Response(null, {
            status: 200,
            headers: { ETag: `"${sessionId}-etag"` },
          });
        }
        if (pathParts[0] === 'multipart') {
          const sessionId = pathParts[1]!;
          const partNumber = parseInt(pathParts[2]!, 10);
          if (rawBody) {
            if (!this.partBodies.has(sessionId)) {
              this.partBodies.set(sessionId, new Map());
            }
            this.partBodies.get(sessionId)!.set(partNumber, new Uint8Array(rawBody));
          }
          const etag = `etag-${sessionId}-part-${partNumber}`;
          if (!this.partETags.has(sessionId)) {
            this.partETags.set(sessionId, new Map());
          }
          this.partETags.get(sessionId)!.set(partNumber, etag);
          return new Response(null, {
            status: 200,
            headers: { ETag: `"${etag}"` },
          });
        }
      } finally {
        this.activeUploads--;
      }
    }

    const pathname = rawUrl.startsWith('http') ? new URL(rawUrl).pathname : rawUrl.split('?')[0]!;
    const searchParams = rawUrl.includes('?') ? new URLSearchParams(rawUrl.split('?')[1]) : new URLSearchParams();

    // 1. GET {baseUrl}/config
    if (method === 'GET' && pathname === `${this.baseUrl}/config`) {
      return new Response(JSON.stringify(this.configData), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }

    // 2. POST {baseUrl}/presigned
    if (method === 'POST' && pathname === `${this.baseUrl}/presigned`) {
      const body = (parsedBody ?? {}) as Record<string, unknown>;
      const sessionId = this.generateId();
      this.sessions.set(sessionId, {
        id: sessionId,
        kind: 'presigned',
        status: 'initiated',
        typeId: String(body.document_type_id),
        filename: String(body.filename),
        size: body.declared_size as number | undefined,
        mime: body.declared_mime as string | undefined,
        hash: body.declared_hash as string | undefined,
      });

      return new Response(
        JSON.stringify({
          session_id: sessionId,
          url: `https://storage.test/presigned/${sessionId}`,
          headers: { 'x-amz-server-side-encryption': 'AES256' },
          expires_at: new Date(Date.now() + 3600 * 1000).toISOString(),
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }

    // 3. POST {baseUrl}/presigned/finalize
    if (method === 'POST' && pathname === `${this.baseUrl}/presigned/finalize`) {
      const body = (parsedBody ?? {}) as Record<string, unknown>;
      const sessionId = String(body.session_id);
      const session = this.sessions.get(sessionId);
      if (session) {
        session.status = 'uploaded';
      }

      if (body.documentable_type) {
        // Returns DocumentRecord
        return new Response(
          JSON.stringify({
            id: this.generateId('01DOC'),
            client_filename: session?.filename ?? 'uploaded.file',
            documentable_type: body.documentable_type,
            documentable_id: body.documentable_id,
            status: 'ready',
            storage_file: { id: this.generateId('01FIL') },
          }),
          { status: 201, headers: { 'Content-Type': 'application/json' } }
        );
      }

      return new Response(
        JSON.stringify({
          id: sessionId,
          status: 'uploaded',
          client_filename: session?.filename ?? 'uploaded.file',
          kind: 'presigned',
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }

    // 4. POST {baseUrl}/multipart/initiate
    if (method === 'POST' && pathname === `${this.baseUrl}/multipart/initiate`) {
      const body = (parsedBody ?? {}) as Record<string, unknown>;
      const sessionId = this.generateId();
      const declaredSize = (body.declared_size as number) ?? 10485760;
      const minPartSize = (this.configData.min_part_size as number) ?? 5242880;
      const ONE_MIB = 1024 * 1024;
      const minForParts = Math.ceil(declaredSize / 10000);
      const target = Math.max(minPartSize, minForParts);
      const partSize = Math.ceil(target / ONE_MIB) * ONE_MIB;

      this.sessions.set(sessionId, {
        id: sessionId,
        kind: 'multipart',
        status: 'initiated',
        typeId: String(body.document_type_id),
        filename: String(body.filename),
        size: declaredSize,
        mime: body.declared_mime as string | undefined,
        hash: body.declared_hash as string | undefined,
        partSize,
      });

      const template = loadFixture('multipart-initiate.json') as Record<string, unknown>;
      return new Response(
        JSON.stringify({
          ...template,
          id: sessionId,
          part_size: partSize,
          status: 'initiated',
          client_filename: body.filename,
          declared_size: declaredSize,
        }),
        { status: 201, headers: { 'Content-Type': 'application/json' } }
      );
    }

    // 5. POST {baseUrl}/multipart/part-urls
    if (method === 'POST' && pathname === `${this.baseUrl}/multipart/part-urls`) {
      const body = (parsedBody ?? {}) as Record<string, unknown>;
      const sessionId = String(body.session_id);
      const partNumbers = (body.part_numbers as number[]) ?? [];
      const urls: Record<string, string> = {};
      for (const p of partNumbers) {
        urls[String(p)] = `https://storage.test/multipart/${sessionId}/${p}`;
      }
      return new Response(JSON.stringify({ urls }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }

    // 6. GET {baseUrl}/multipart/parts
    if (method === 'GET' && pathname === `${this.baseUrl}/multipart/parts`) {
      const sessionId = searchParams.get('session_id') ?? '';
      const partsMap = this.partETags.get(sessionId);
      const parts: Array<{ PartNumber: number; ETag: string }> = [];
      if (partsMap) {
        for (const [pNum, etag] of partsMap.entries()) {
          parts.push({ PartNumber: pNum, ETag: `"${etag}"` });
        }
      }
      return new Response(JSON.stringify({ parts }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }

    // 7. GET {baseUrl}/multipart/status
    if (method === 'GET' && pathname === `${this.baseUrl}/multipart/status`) {
      const sessionId = searchParams.get('session_id') ?? '';
      const session = this.sessions.get(sessionId);
      if (!session) {
        const missingTmpl = loadFixture('multipart-status-missing.json');
        return new Response(JSON.stringify(missingTmpl), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        });
      }
      return new Response(
        JSON.stringify({
          exists: true,
          status: session.status,
          expires_at: new Date(Date.now() + 3600 * 1000).toISOString(),
          disk: 'test_disk',
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }

    // 8. POST {baseUrl}/multipart/complete
    if (method === 'POST' && pathname === `${this.baseUrl}/multipart/complete`) {
      const body = (parsedBody ?? {}) as Record<string, unknown>;
      const sessionId = String(body.session_id);
      const session = this.sessions.get(sessionId);
      if (session) {
        session.status = 'uploaded';
      }

      if (body.documentable_type) {
        return new Response(
          JSON.stringify({
            id: this.generateId('01DOC'),
            client_filename: session?.filename ?? 'multipart.file',
            documentable_type: body.documentable_type,
            documentable_id: body.documentable_id,
            status: 'ready',
            storage_file: { id: this.generateId('01FIL') },
          }),
          { status: 201, headers: { 'Content-Type': 'application/json' } }
        );
      }

      const completeTmpl = loadFixture('multipart-complete.json') as Record<string, unknown>;
      return new Response(
        JSON.stringify({
          ...completeTmpl,
          id: sessionId,
          status: 'uploaded',
          client_filename: session?.filename ?? 'multipart.file',
          object_size: session?.size ?? 10485760,
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }

    // 9. POST {baseUrl}/multipart/abort
    if (method === 'POST' && pathname === `${this.baseUrl}/multipart/abort`) {
      const body = (parsedBody ?? {}) as Record<string, unknown>;
      const sessionId = String(body.session_id);
      const session = this.sessions.get(sessionId);
      if (session) {
        session.status = 'aborted';
      }
      return new Response(null, { status: 204 });
    }

    return new Response(JSON.stringify({ message: 'Route not found' }), {
      status: 404,
      headers: { 'Content-Type': 'application/json' },
    });
  };
}
