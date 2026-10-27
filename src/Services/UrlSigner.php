<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use LogicException;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Exceptions\DiskDoesNotSupportTemporaryUrlsException;
use MadeByClowd\Documentable\Exceptions\DocumentNotReady;
use MadeByClowd\Documentable\Models\Document;
use RuntimeException;

/**
 * Signs time-limited download URLs and builds the `Content-Disposition` value that goes with them.
 *
 * @internal
 */
final class UrlSigner
{
    public function __construct(private readonly AccessLogger $accessLogger) {}

    /**
     * @throws DocumentNotReady when the document is still being verified, or was refused
     * @throws DiskDoesNotSupportTemporaryUrlsException when the document's disk cannot sign temporary URLs
     */
    public function sign(Document $document, DateTimeInterface $expiration, string $disposition = 'inline'): string
    {
        $storageFile = $document->storageFile ?? throw new LogicException("Document {$document->id} has no storage file to sign a URL for.");

        if ($document->status !== DocumentStatus::Committed) {
            throw new DocumentNotReady($document->id, $document->status);
        }

        try {
            $url = Storage::disk($storageFile->disk)->temporaryUrl(
                $storageFile->path,
                $expiration,
                [
                    'ResponseContentDisposition' => self::contentDisposition($disposition, $document->client_filename),
                ]
            );
        } catch (RuntimeException $e) {
            throw DiskDoesNotSupportTemporaryUrlsException::forDisk($storageFile->disk, $e);
        }

        $this->accessLogger->log($document, $disposition === 'attachment' ? 'download' : 'view');

        return $url;
    }

    /**
     * The `Content-Disposition` value for a download (RFC 6266): a plain ASCII `filename` every client
     * understands, plus `filename*` (RFC 5987, UTF-8, percent-encoded) when the real name has anything else, so a
     * browser saves "résumé.pdf" as written.
     */
    public static function contentDisposition(string $disposition, string $filename): string
    {
        $ascii = self::sanitizeHeaderFilename($filename);
        $value = $disposition.'; filename="'.($ascii === '' ? 'download' : $ascii).'"';

        $clean = (string) preg_replace('/[\x00-\x1F\x7F"]/', '', $filename);

        if ($clean !== '' && ! mb_check_encoding($clean, 'ASCII')) {
            $value .= "; filename*=UTF-8''".rawurlencode($clean);
        }

        return $value;
    }

    /**
     * The ASCII-only form of a name that is safe inside a quoted header value: quotes and control characters are
     * dropped (the header-injection primitives), a backslash or any non-ASCII character becomes `_`. Pure and
     * static so a consumer building their own download endpoint can reuse it.
     */
    public static function sanitizeHeaderFilename(string $filename): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F"]/', '', $filename);

        // A failed match yields an empty name rather than an unsanitized one.
        return $stripped === null ? '' : (string) preg_replace('/[^\x20-\x7E]|\\\\/u', '_', $stripped);
    }
}
