<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Support\Facades\Storage;
use LogicException;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Exceptions\DocumentNotReady;
use MadeByClowd\Documentable\Exceptions\DocumentTooLargeToRead;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\StorageFile;
use RuntimeException;
use Throwable;

/**
 * Handles reading document contents and stream resources from storage.
 *
 * @internal
 */
final class DocumentReader
{
    public function __construct(
        private readonly AccessLogger $accessLogger,
    ) {}

    /**
     * @return resource
     *
     * @throws DocumentNotReady
     * @throws LogicException
     * @throws RuntimeException
     */
    public function readStream(Document $document)
    {
        $storageFile = $this->readable($document);

        try {
            $stream = Storage::disk($storageFile->disk)->readStream($storageFile->path);
        } catch (Throwable $e) {
            throw new RuntimeException("Could not read [{$storageFile->path}] on disk [{$storageFile->disk}]: {$e->getMessage()}", 0, $e);
        }

        if (! is_resource($stream)) {
            throw new RuntimeException("Could not read [{$storageFile->path}] on disk [{$storageFile->disk}].");
        }

        $this->accessLogger->log($document, 'read');

        return $stream;
    }

    /**
     * The stored size can be wrong, so the read itself stops one byte past the limit.
     *
     * @throws DocumentNotReady
     * @throws DocumentTooLargeToRead
     * @throws LogicException
     * @throws RuntimeException
     */
    public function readContents(Document $document, int $maxBytes): string
    {
        $storageFile = $this->readable($document);

        if ($storageFile->size_bytes > $maxBytes) {
            throw new DocumentTooLargeToRead($document->id, $storageFile->size_bytes, $maxBytes);
        }

        $stream = $this->readStream($document);

        try {
            $contents = stream_get_contents($stream, $maxBytes + 1);
        } finally {
            fclose($stream);
        }

        if ($contents === false) {
            throw new RuntimeException("Could not read [{$storageFile->path}] on disk [{$storageFile->disk}].");
        }

        if (strlen($contents) > $maxBytes) {
            throw new DocumentTooLargeToRead($document->id, strlen($contents), $maxBytes);
        }

        return $contents;
    }

    private function readable(Document $document): StorageFile
    {
        $storageFile = $document->storageFile ?? throw new LogicException("Document {$document->id} has no storage file to read.");

        if ($document->status !== DocumentStatus::Committed) {
            throw new DocumentNotReady($document->id, $document->status);
        }

        return $storageFile;
    }
}
