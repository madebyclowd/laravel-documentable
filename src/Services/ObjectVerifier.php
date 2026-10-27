<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use finfo;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Contracts\ScanResult;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Exceptions\ScannerUnavailable;
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
    /** Enough for fileinfo and every magic-byte check; one small ranged read. */
    private const HEAD_BYTES = 8192;

    /** What fileinfo answers for a format it has no signature for. */
    private const GENERIC_MIMES = ['application/octet-stream', 'text/plain'];

    public function __construct(
        private readonly ScansUploadedFile $scanner,
        private readonly Config $laravelConfig,
        private readonly DocumentableConfig $config,
        private readonly Container $container,
    ) {}

    /**
     * The one entry point every transport funnels through (local upload, multipart finalize, direct-PUT
     * finalize, background verification): the size, the extension, the content type and the signature are all
     * resolved from the bytes and the filename here, so no transport can skip a rule or trust a client-asserted
     * type.
     *
     * The content type is sniffed from `$head`, the object's first bytes. A type that lists extensions and sets
     * `trust_extension_for_generic_mime` also accepts a file fileinfo can only call `application/octet-stream` or
     * `text/plain`, when the extension is on the list; a specific type that is not allowed is never excused.
     *
     * @throws ValidationException
     */
    public function assertUploadRules(DocumentType $type, string $filename, int $sizeBytes, string $head): void
    {
        $this->assertSize($sizeBytes, $type);

        $extension = self::extensionOf(FilenameNormalizer::normalize($filename));
        $this->assertExtension($type, $extension);

        $mime = $this->mimeOf($head);
        $this->assertMime($type, $extension, $mime);

        foreach ($this->config->validators[$type->code] ?? [] as $class) {
            $reason = $this->container->make($class)->validate($type, $filename, $head);

            if ($reason !== null) {
                throw ValidationException::withMessages(['file' => $reason]);
            }
        }
    }

    /**
     * The lowercase extension after the last dot of a normalized name, or an empty string for a name with none.
     */
    private static function extensionOf(string $normalizedName): string
    {
        $dot = strrpos($normalizedName, '.');

        return $dot === false ? '' : strtolower(substr($normalizedName, $dot + 1));
    }

    private function assertSize(int $sizeBytes, DocumentType $type): void
    {
        if ($sizeBytes > $type->max_size_mb * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => "File size exceeds limit of {$type->max_size_mb}MB.",
            ]);
        }
    }

    private function assertExtension(DocumentType $type, string $extension): void
    {
        if ($type->allowed_extensions && ! in_array($extension, $type->allowed_extensions, true)) {
            $shown = $extension === '' ? 'without an extension' : "'.{$extension}'";

            throw ValidationException::withMessages([
                'file' => "File extension {$shown} is not allowed.",
            ]);
        }
    }

    private function assertMime(DocumentType $type, string $extension, string $mime): void
    {
        if (! $type->allowed_mimes || in_array($mime, $type->allowed_mimes, true)) {
            return;
        }

        $trusted = $type->trust_extension_for_generic_mime
            && in_array($mime, self::GENERIC_MIMES, true)
            && $extension !== ''
            && in_array($extension, $type->allowed_extensions ?? [], true);

        if (! $trusted) {
            throw ValidationException::withMessages([
                'file' => "File type '{$mime}' is not allowed.",
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

        if ($result === ScanResult::Error && $this->laravelConfig->get('documentable.security.scan_error_policy', 'reject') === 'allow') {
            Log::warning("Documentable stored [{$path}] on disk [{$disk}] without a scan verdict, because the scanner reported an error and security.scan_error_policy is 'allow'.");

            return;
        }

        if ($result->isInfected()) {
            throw ValidationException::withMessages(['file' => 'Uploaded file failed a security scan.']);
        }

        throw ScannerUnavailable::withMessages(['file' => 'Uploaded file could not be scanned. Try again later.']);
    }

    /**
     * Stream the object once: sha256 over the whole body (integrity, independent of any provider ETag) and
     * mime sniffed from the first bytes via fileinfo, never from a client-asserted field. Memory stays constant
     * whatever the size, because the body is hashed as it is read and never held.
     *
     * @return array{hash: string, mime: string, size: int}
     */
    public function hashAndDetectMime(string $disk, string $path): array
    {
        return $this->withStream($disk, $path, function ($stream) {
            $head = (string) stream_get_contents($stream, self::HEAD_BYTES);

            $context = hash_init('sha256');
            hash_update($context, $head);
            $rest = hash_update_stream($context, $stream);

            return [
                'hash' => hash_final($context),
                'mime' => $this->mimeOf($head),
                'size' => strlen($head) + $rest,
            ];
        });
    }

    /**
     * The first `$bytes` of the object. The stream is lazy, so only that much is fetched from the provider,
     * which keeps a mime or magic-byte check cheap on a multi-gigabyte object.
     */
    public function head(string $disk, string $path, int $bytes = self::HEAD_BYTES): string
    {
        return $this->withStream($disk, $path, function ($stream) use ($bytes) {
            return stream_get_contents($stream, $bytes) ?: '';
        });
    }

    /**
     * The object's size from the disk's metadata (a HEAD request on S3), without reading the body.
     */
    public function size(string $disk, string $path): int
    {
        return Storage::disk($disk)->size($path);
    }

    /**
     * @template T
     *
     * @param  callable(resource): T  $read
     * @return T
     */
    private function withStream(string $disk, string $path, callable $read): mixed
    {
        $stream = Storage::disk($disk)->readStream($path)
            ?? throw new RuntimeException("Could not open [{$path}] on disk [{$disk}] for reading.");

        try {
            return $read($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * The content type fileinfo reads from an object's first bytes, never from a client-asserted field.
     */
    public function mimeOf(string $head): string
    {
        return (new finfo(FILEINFO_MIME_TYPE))->buffer($head) ?: 'application/octet-stream';
    }
}
