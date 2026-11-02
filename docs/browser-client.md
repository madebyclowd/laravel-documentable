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
| `resumeSessionId` | `string` | `undefined` | Multipart upload session ID to resume after interruption. |
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
| `network` | A network request failed or disconnected. |
| `validation` | Server validation failed (HTTP 422). Form errors are available on `error.errors`. |
| `forbidden` | Authorization failed (HTTP 401 or 403). |
| `server` | Storage or application server returned an unexpected error. |
| `part_failed` | A multipart chunk upload failed after exhausting retries. |
| `rejected` | The document failed background verification and transitioned to `rejected`. |
| `processing_timeout` | Document processing did not complete within `processingTimeoutMs`. |

## Resuming an upload

When a multipart upload is interrupted by a page reload or network drop, pass `resumeSessionId` with the previous session ID. The helper checks `GET /documents/multipart/status` and `GET /documents/multipart/parts`, skips already uploaded chunks, and continues uploading remaining parts.

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
