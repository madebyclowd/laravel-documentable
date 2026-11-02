# Reading documents

## Model accessors and scopes

`Document` models provide accessors for file properties:

```php
$document->filename;       // Client filename (e.g. "report.pdf")
$document->size;           // File size in bytes from the storage file
$document->mime;           // MIME type from the storage file (e.g. "application/pdf")
$document->extension;      // Lowercase extension from filename ("pdf"), or "" when none
$document->temporaryUrl(); // Presigned download URL delegating to DocumentService::getUrl()
```

If the `storageFile` relation is not already loaded, accessing `size` or `mime` loads it lazily once. If the backing storage file record is missing, a `LogicException` is thrown.

Under `Model::shouldBeStrict()`, Eloquent prevents accessing missing attributes on models. Defined accessors above are always safe to read, whereas accessing undefined properties throws an exception.

Query scopes on `Document`:

```php
// Only the current version of each group or slot (Eloquent's own latest() orders by created_at)
Document::latestVersion()->get();

// Only committed documents
Document::committed()->get();

// Filter by document type code in a single subquery (no whereHas per row)
Document::ofType('INVOICE')->get();
```

## Owner model helpers

Models using the `Documentable` trait can query their documents by type:

```php
// The latest document of a type, in one query:
$avatar = $user->documentOfType('avatar')->first();

// MorphMany relation to all documents of a type:
$invoices = $user->documentsOfType('invoice')->get();

// Eager load for many owners in one extra query. Only the latest document of that type lands in `documents`:
$users = User::withDocumentOfType('avatar')->get();
```

Helpers respect string `key_type` configurations and integer primary key owners.

## Reading contents and streams

Read stored bytes safely without bypassing the service or directly accessing storage disks:

```php
// Open a readable stream (PHP resource):
$stream = $documentService->readStream($document);
$firstChunk = fread($stream, 8192);
fclose($stream);

// Read entire contents into a string with a memory limit guard (default 10MB):
$contents = $documentService->readContents($document, maxBytes: 5 * 1024 * 1024);
```

Both methods refuse non-committed documents (`DocumentNotReady`), throw `DocumentTooLargeToRead` when exceeding the byte limit, and record an access log entry with action `read` when `documentable.audit.access_log` is enabled.

## API resources

Use `DocumentResource` to serialize documents with `id`, `filename`, `size`, `mime`, `version`, and `type`.

To prevent accidental generation of presigned URLs during bulk listings, presigned `url` generation is off by default. Opt in explicitly with `->withUrl()`:

```php
use MadeByClowd\Documentable\Http\Resources\DocumentResource;

// Presigned URL omitted by default:
return new DocumentResource($document);

// Presigned URL included:
return (new DocumentResource($document))->withUrl();

// Collections:
return DocumentResource::collection($documents)->withUrl();
```

## Download links

Stable HTTP endpoints for viewing and downloading documents:

```http
GET /documents/{document}/download
GET /documents/{document}/view
```

- `download` redirects (302) to a presigned URL with `Content-Disposition: attachment`.
- `view` redirects (302) to a presigned URL with `Content-Disposition: inline`.
- Responses include `Cache-Control: no-store` to prevent caching the redirect beyond URL expiration.
- Authorized through `AuthorizesDocumentAccess::canView` (returns 403 on denial).
- Returns 409 Conflict if the document is still `processing` or was `rejected`.
- Returns 404 if the document is missing or soft-deleted.
- Generates access audit log entries when `documentable.audit.access_log` is enabled.

## Custom URL options and modifiers

When generating URLs programmatically via `DocumentService::getUrl()` or `$document->temporaryUrl()`, pass a `UrlOptions` value object:

```php
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

$options = new UrlOptions(
    ttl: now()->addMinutes(15),
    disposition: 'inline',
    filename: 'custom-export-name.pdf',
    contentType: 'application/pdf',
);

$url = $documentService->getUrl($document, $options);
```

For security, `inline` disposition only allows content types from a safe allowlist (`image/png`, `image/jpeg`, `image/webp`, `image/gif`, `application/pdf`). HTML and SVG are rejected to prevent XSS.

To hook into URL generation globally, implement `ModifiesUrlOptions` and set `documentable.url.modifier` in config:

```php
use MadeByClowd\Documentable\Contracts\ModifiesUrlOptions;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

class CustomUrlModifier implements ModifiesUrlOptions
{
    public function modify(UrlOptions $options, Document $document): UrlOptions
    {
        return new UrlOptions(
            ttl: $options->ttl,
            disposition: $options->disposition,
            filename: 'prefix-'.$options->filename,
            contentType: $options->contentType,
        );
    }
}
```
