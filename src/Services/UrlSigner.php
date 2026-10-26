<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use LogicException;
use MadeByClowd\Documentable\Exceptions\DiskDoesNotSupportTemporaryUrlsException;
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
     * @throws DiskDoesNotSupportTemporaryUrlsException when the document's disk cannot sign temporary URLs
     */
    public function sign(Document $document, DateTimeInterface $expiration, string $disposition = 'inline'): string
    {
        $storageFile = $document->storageFile ?? throw new LogicException("Document {$document->id} has no storage file to sign a URL for.");

        $filename = self::sanitizeHeaderFilename($document->client_filename);

        try {
            $url = Storage::disk($storageFile->disk)->temporaryUrl(
                $storageFile->path,
                $expiration,
                [
                    'ResponseContentDisposition' => $disposition.'; filename="'.$filename.'"',
                ]
            );
        } catch (RuntimeException $e) {
            throw DiskDoesNotSupportTemporaryUrlsException::forDisk($storageFile->disk, $e);
        }

        $this->accessLogger->log($document, $disposition === 'attachment' ? 'download' : 'view');

        return $url;
    }

    /**
     * Strips quotes and control characters, the header-injection primitives in a `Content-Disposition`
     * filename. Pure and static so a consumer building their own download endpoint can reuse it.
     */
    public static function sanitizeHeaderFilename(string $filename): string
    {
        // A failed match yields an empty name rather than an unsanitized one.
        return preg_replace('/[\x00-\x1F\x7F"]/', '', $filename) ?? '';
    }
}
