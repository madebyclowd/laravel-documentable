<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use finfo;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Contracts\ScanResult;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Models\DocumentType;
use RuntimeException;

/**
 * Checks an uploaded object: size, sniffed mime type, content hash, integrity against the client's claim and
 * the scanner verdict. It only reports (by returning facts or throwing {@see ValidationException}); whether to
 * delete the object after a failure is the caller's decision, because that depends on who wrote it.
 *
 * @internal
 */
final class ObjectVerifier
{
    public function __construct(
        private readonly ScansUploadedFile $scanner,
        private readonly Config $config,
    ) {}

    /**
     * The one entry point every transport funnels through (local upload, multipart finalize, direct-PUT
     * finalize): size and mime are resolved from the bytes first, then checked here, so no transport can skip
     * validation or trust a client-asserted mime type.
     *
     * @throws ValidationException
     */
    public function assertSizeAndMime(int $sizeBytes, ?string $mimeType, DocumentType $type): void
    {
        $maxBytes = $type->max_size_mb * 1024 * 1024;

        if ($sizeBytes > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => "File size exceeds limit of {$type->max_size_mb}MB.",
            ]);
        }

        if ($type->allowed_mimes && (! $mimeType || ! in_array($mimeType, $type->allowed_mimes, true))) {
            throw ValidationException::withMessages([
                'file' => "File type '{$mimeType}' is not allowed.",
            ]);
        }
    }

    /**
     * Compare the server-computed hash with the one the client declared, without touching storage.
     *
     * @throws ValidationException
     */
    public function assertIntegrity(string $actualHash, ?string $expectedHash): void
    {
        if ($expectedHash === null || hash_equals($expectedHash, $actualHash)) {
            return;
        }

        throw ValidationException::withMessages([
            'file' => "Integrity check failed. Server hash ({$actualHash}) does not match client hash ({$expectedHash}).",
        ]);
    }

    /**
     * Ask the scanner for a verdict. Returns when the object may be kept: clean, or a scanner error under
     * `security.scan_error_policy = allow` (logged).
     *
     * @throws ValidationException when the object is infected, or could not be scanned under the `reject` policy
     */
    public function assertScanClean(string $disk, string $path): void
    {
        $result = $this->scanner->scan($disk, $path);

        if ($result === ScanResult::Clean) {
            return;
        }

        if ($result === ScanResult::Error && $this->config->get('documentable.security.scan_error_policy', 'reject') === 'allow') {
            Log::warning("Documentable stored [{$path}] on disk [{$disk}] without a scan verdict, because the scanner reported an error and security.scan_error_policy is 'allow'.");

            return;
        }

        throw ValidationException::withMessages([
            'file' => $result->isInfected()
                ? 'Uploaded file failed a security scan.'
                : 'Uploaded file could not be scanned. Try again later.',
        ]);
    }

    /**
     * Hash, mime and size of an assembled multipart object. When `use_native_checksum` is on and the driver
     * returns a full-object checksum, the full re-download-and-hash is skipped: only the first chunk is read
     * (for mime sniffing) and the size comes from the disk. Falls back to the full-stream hash whenever the
     * driver returns null, so it is never the only integrity path.
     *
     * @return array{hash: string, mime: string, size: int}
     */
    public function assembledObjectInfo(MultipartUploadDriver $driver, string $disk, string $path): array
    {
        if ($this->config->get('documentable.multipart.use_native_checksum', false)) {
            $checksum = $driver->retrieveChecksum($disk, $path);

            if ($checksum !== null) {
                return [
                    'hash' => $checksum,
                    'mime' => $this->detectMimeFromHead($disk, $path),
                    'size' => Storage::disk($disk)->size($path),
                ];
            }
        }

        return $this->hashAndDetectMime($disk, $path);
    }

    /**
     * Stream the object once: sha256 over the whole body (integrity, independent of any provider ETag) and
     * mime sniffed from the first chunk via fileinfo, never from a client-asserted field.
     *
     * @return array{hash: string, mime: string, size: int}
     */
    public function hashAndDetectMime(string $disk, string $path): array
    {
        $stream = $this->openStream($disk, $path);

        $ctx = hash_init('sha256');
        $sizeBytes = 0;
        $firstChunk = null;

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);

            if ($chunk === false) {
                fclose($stream);

                throw new RuntimeException("Could not read [{$path}] from disk [{$disk}].");
            }

            if ($firstChunk === null) {
                $firstChunk = $chunk;
            }

            hash_update($ctx, $chunk);
            $sizeBytes += strlen($chunk);
        }

        fclose($stream);

        return [
            'hash' => hash_final($ctx),
            'mime' => $this->sniffMime($firstChunk ?? ''),
            'size' => $sizeBytes,
        ];
    }

    /**
     * Sniff mime from only the first chunk: cheap regardless of object size, since the stream is lazy and
     * this never drains it.
     */
    private function detectMimeFromHead(string $disk, string $path): string
    {
        $stream = $this->openStream($disk, $path);
        $chunk = fread($stream, 8192);
        fclose($stream);

        return $this->sniffMime($chunk ?: '');
    }

    /**
     * @return resource
     */
    private function openStream(string $disk, string $path)
    {
        return Storage::disk($disk)->readStream($path)
            ?? throw new RuntimeException("Could not open [{$path}] on disk [{$disk}] for reading.");
    }

    private function sniffMime(string $head): string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: 'application/octet-stream';
    }
}
