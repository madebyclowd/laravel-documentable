# Browser client

The package includes a standalone, dependency-free TypeScript client helper for browsers and frontends. Publish the TypeScript source into your application:

```bash
php artisan vendor:publish --tag=documentable-client
```

This copies the client helper to `resources/js/vendor/documentable/documentable.ts`.

## Basic usage

```typescript
import { uploadFile } from '@/vendor/documentable/documentable';

async function handleFileSelect(event: Event) {
  const file = (event.target as HTMLInputElement).files?.[0];
  if (!file) return;

  try {
    const result = await uploadFile(file, {
      type: 'passport',
      owner: { type: 'user', id: 42 },
      onProgress: ({ loaded, total }) => {
        console.log(`Uploaded ${loaded} of ${total} bytes`);
      },
    });

    console.log('Upload complete, document ID:', result.document?.id);
  } catch (error) {
    console.error('Upload failed:', error);
  }
}
```

## Upload options (`UploadOptions`)

| Option | Type | Default | Description |
|---|---|---|---|
| `type` | `string` | *(required)* | Document type code or UUID from `/config`. |
| `owner` | `{ type: string; id: string \| number }` | `undefined` | Present: attach in the same flow; absent: stop at an uploaded session. |
| `metadata` | `Record<string, unknown>` | `undefined` | Key-value metadata passed to finalize or attach. |
| `documentGroupId` | `string` | `undefined` | Attach as a new version of this existing document group; it must already belong to the same owner and type. |
| `onProgress` | `(p: { loaded: number; total: number }) => void` | `undefined` | Callback receiving loaded and total byte counts. |
| `signal` | `AbortSignal` | `undefined` | Signal to abort in-flight hashing, uploads, or polling waits. |
| `resumeSessionId` | `string` | `undefined` | Multipart upload session ID to resume after interruption. See [Connection loss and resuming](#connection-loss-and-resuming). |
| `onSession` | `(sessionId: string) => void` | `undefined` | Multipart uploads only. Called once the session exists, for a new session and for a resumed one. Store the id to resume later. |
| `dedup` | `boolean` | `true` | When true, attempts a proof-of-possession handshake before uploading bytes. |
| `waitForProcessing` | `boolean` | `true` | Polls the show route while the attached document status is `processing`. |
| `processingTimeoutMs` | `number` | `120000` | Maximum milliseconds to wait for a `processing` document. |
| `pollIntervalMs` | `number` | `2000` | Milliseconds between status polls to `GET /documents/{document}`. |

## Client configuration (`ClientOptions`)

`createUploader(client?: ClientOptions)` and `uploadFile(file, options, client?: ClientOptions)` accept configuration options:

| Option | Type | Default | Description |
|---|---|---|---|
| `baseUrl` | `string` | `'/documents'` | Base path matching `documentable.route_prefix`. |
| `fetch` | `typeof fetch` | `globalThis.fetch` | Fetch implementation used for all HTTP requests. |
| `headers` | `() => Record<string, string> \| Promise<Record<string, string>>` | auto-detected | Headers added to server requests. In browsers, defaults to `X-Requested-With: XMLHttpRequest` and `X-XSRF-TOKEN` (decoded from the `XSRF-TOKEN` cookie). Providing a callback replaces this default entirely. Storage PUT requests never receive application headers. |
| `routes` | `ClientRoutes` | `undefined` | Custom route mapping for Wayfinder or non-standard route names. |
| `isOnline` | `() => boolean` | `navigator.onLine` | Tells the helper whether the browser is online. Returns `true` where there is no `navigator`. |
| `waitForOnline` | `(signal?: AbortSignal) => Promise<void>` | waits for the window `online` event | Called when a request fails while `isOnline()` is `false`. |

## Route mapping (`routes`)

For applications generating routes dynamically (such as Wayfinder), provide route functions returning `{ url: string }`:

```typescript
import { createUploader } from '@/vendor/documentable/documentable';

const uploader = createUploader({
  routes: {
    config: () => ({ url: '/api/documents/config' }),
    presign: () => ({ url: '/api/documents/presigned' }),
    presignFinalize: () => ({ url: '/api/documents/presigned/finalize' }),
    attach: () => ({ url: '/api/documents/attach' }),
    handshake: () => ({ url: '/api/documents/handshake' }),
    handshakeAnswer: () => ({ url: '/api/documents/handshake/answer' }),
    multipartInitiate: () => ({ url: '/api/documents/multipart/initiate' }),
    multipartPartUrls: () => ({ url: '/api/documents/multipart/part-urls' }),
    multipartParts: () => ({ url: '/api/documents/multipart/parts' }),
    multipartStatus: () => ({ url: '/api/documents/multipart/status' }),
    multipartComplete: () => ({ url: '/api/documents/multipart/complete' }),
    multipartAbort: () => ({ url: '/api/documents/multipart/abort' }),
    show: ({ document }) => ({ url: `/api/documents/${document}` }),
  },
});
```

Query strings for GET endpoints (such as `session_id`) are automatically appended with `?` or `&`.

## Error codes (`UploadErrorCode`)

`UploadError` errors expose a `code` property:

| Error Code | Meaning |
|---|---|
| `aborted` | Upload, hashing, or polling was cancelled via `AbortSignal`. |
| `file_too_large` | File size exceeds `max_bytes` allowed for the document type. |
| `type_not_allowed` | File extension is not in `allowed_extensions` for the document type. |
| `unknown_type` | Document type identifier was not found in the client configuration. |
| `network` | A network request failed or disconnected. When a multipart session was kept, `error.sessionId` holds its id. |
| `validation` | Server validation failed (HTTP 422). Form errors are available on `error.errors`. |
| `forbidden` | Authorization failed (HTTP 401 or 403). |
| `server` | Storage or application server returned an unexpected error. |
| `part_failed` | A multipart chunk upload failed after exhausting retries. `error.sessionId` holds the kept session id. |
| `rejected` | The document failed background verification and transitioned to `rejected`. |
| `processing_timeout` | Document processing did not complete within `processingTimeoutMs`. |

## Connection loss and resuming

What the helper does when the connection drops, and what it leaves to your app.

**It retries on its own**
- A part upload that fails with a network error, a 5xx or a 429 is retried with exponential backoff and jitter. The numbers come from `documentable.client.retry`, which `GET /documents/config` reports. The defaults are 3 retries with delays of at most 0.5, 1 and 2 seconds, so a lost connection that the browser does not report survives about 3.5 seconds at most. Raise `attempts` and `max_delay_ms` if your users upload over weak connections.
- Signing part URLs and reading session status and parts are retried the same way. They are safe to repeat.
- A 4xx other than 429 is not retried, because repeating a refusal does not help.
- While the browser reports it is offline (`navigator.onLine` is `false`), a failed request waits for the window `online` event instead of using up attempts. A connection that drops without the browser noticing falls back to the retries above.

**It does not retry everything**
- `initiate` and `complete` are not retried. A second `initiate` after a lost reply could leave a stray session, and `complete` must not run twice.
- A small file uploads with one `PUT`. If it fails, the whole file starts again. There is nothing to resume.

**It keeps the session, and tells you its id**
- After a network error, a 5xx, a 429 or an exhausted part, the helper keeps the multipart session on the server and sets `error.sessionId`. It calls `onSession(id)` as soon as the session exists.
- After a refusal (403, 422, a type or size error) or your own abort, the helper aborts the session. `error.sessionId` is not set.
- A kept session expires after `documentable.multipart.session_ttl_hours` (24 hours by default). After that, a resume starts a new upload.

**Resuming is yours to trigger.** The helper does not save the session id, so a page reload forgets it. Save it yourself, then pass it back with the same file:

```ts
const key = `upload:${file.name}:${file.size}`;

try {
  await uploadFile(file, {
    type: 'archive',
    resumeSessionId: localStorage.getItem(key) ?? undefined,
    onSession: id => localStorage.setItem(key, id),
  });
  localStorage.removeItem(key);
} catch (error) {
  // Keep the saved id when the error has a sessionId: the next attempt continues from the finished parts.
}
```

On resume the helper calls `GET /documents/multipart/status` and `GET /documents/multipart/parts`, skips the parts the bucket already has, and uploads the rest. If the session is missing, expired or no longer open, it starts a new upload. Use the same file: the helper cannot check that it is.

## Deduplication handshake

When `dedup !== false` and `file.size > 0`, the client issues a handshake request to `POST /documents/handshake` after hashing the file. The server returns a challenge with random byte ranges. The client reads those ranges, computes `sha256(nonce || range_bytes)`, and posts the proof to `POST /documents/handshake/answer`.

If the server already has the file, it returns HTTP 201 with an uploaded session immediately, skipping raw byte transfer. If the file is not on the server, the answer returns HTTP 422 (a decoy challenge and an invalid proof are deliberately indistinguishable to prevent existence enumeration attacks). The client treats HTTP 422 as a miss and continues with normal upload. Any non-abort network or server error during the handshake also falls back to normal upload.

## Waiting for processing

When a document requires background verification (for files exceeding `documentable.verification.sync_hash_max_bytes`), the server attaches it with status `processing`. When `waitForProcessing !== false`, the helper polls `GET /documents/{document}` every `pollIntervalMs` until the status becomes `committed` or `rejected`. If the status becomes `rejected`, it throws `UploadError('rejected')`. If the timeout (`processingTimeoutMs`, default 120 seconds) is reached, it throws `UploadError('processing_timeout')` while leaving the document on the server.

## Document status endpoint

```http
GET /documents/{document}
```

Returns the document JSON with its current `status` (`committed`, `processing`, or `rejected`), matching the shape returned by attach and multipart complete.

## Frontend integration notes

- Compare `file.size` against `multipart.threshold_bytes` on the client. Route to `/documents/presigned` below the threshold and `/documents/multipart/initiate` at or above it.
- Responses return raw JSON bodies without status envelopes.
- S3 requires minimum 5MB part sizes except for the final part. 5MB to 10MB parts are recommended.
- Under `etag_strategy = client`, capture the `ETag` response header from each part PUT.
- Call `/documents/multipart/abort` when an upload is cancelled to release bucket storage immediately.
- Retry failed part PUTs before abandoning the upload.
- Compute SHA-256 on the client when possible.
- Pass the morph alias and key for `documentable_type` and `documentable_id`.
