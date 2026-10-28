<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Events\DocumentProcessed;
use MadeByClowd\Documentable\Events\DocumentRejected;
use MadeByClowd\Documentable\Exceptions\ScannerUnavailable;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Repositories\StorageFileRepository;
use RuntimeException;

/**
 * Settles a `processing` document: reads its object once, applies the same rules a small upload gets in the
 * request, then either files it under its real hash and commits it, or rejects it and removes the object.
 *
 * An outage (storage unreachable, scanner down) is not a verdict: it throws, the document stays `processing`
 * and the object stays put, so the queue can try again.
 *
 * @internal
 */
final class ProcessingDocumentVerifier
{
    public function __construct(
        private readonly ObjectVerifier $verifier,
        private readonly UploadFinalizer $finalizer,
        private readonly DocumentRepository $documents,
        private readonly StorageFileRepository $storageFiles,
        private readonly ResolvesDedupScope $dedupScope,
        private readonly DedupUserResolver $users = new DedupUserResolver,
    ) {}

    /**
     * Idempotent: a document that is gone or no longer `processing` is left alone.
     *
     * @throws RuntimeException when the object or the scanner cannot be reached
     */
    public function verify(string $documentId): void
    {
        $document = Document::with(['storageFile', 'documentType'])->find($documentId);

        if ($document === null || $document->status !== DocumentStatus::Processing) {
            return;
        }

        $stored = $document->storageFile ?? throw new LogicException("Processing document {$documentId} has no stored file.");
        $session = UploadSession::where('storage_file_id', $stored->id)->first()
            ?? throw new LogicException("Processing document {$documentId} has no upload session to verify against.");

        $facts = $this->verifier->hashAndDetectMime($stored->disk, $stored->path);

        try {
            $this->finalizer->assertAcceptable($session, $facts);
        } catch (ScannerUnavailable $e) {
            throw new RuntimeException('The scanner could not give a verdict; the verification will be retried.', 0, $e);
        } catch (ValidationException $e) {
            $this->reject($document, $stored, collect($e->errors())->flatten()->implode(' '));

            return;
        }

        $this->promote($document, $stored, $facts);
    }

    /**
     * @param  array{hash: string, mime: string, size: int}  $facts
     */
    private function promote(Document $document, StorageFile $stored, array $facts): void
    {
        $type = $document->documentTypeOrFail();
        // The key is computed for the user the upload was started under, as it was at attach time, not for
        // whoever the queue worker happens to be.
        $user = $this->users->resolve(UploadSession::where('storage_file_id', $stored->id)->value('user_id'));
        $key = $this->dedupScope->scopeKey($facts['hash'], $type, $user, $document->documentable);

        try {
            $redundantPath = DB::transaction(fn () => $this->commit($document, $stored, $key, $facts, $user));
        } catch (UniqueConstraintViolationException) {
            // Another verification filed identical bytes under this key first; the second pass finds it.
            $redundantPath = DB::transaction(fn () => $this->commit($document, $stored, $key, $facts, $user));
        }

        if ($redundantPath !== null) {
            Storage::disk($stored->disk)->delete($redundantPath);
        }

        Event::dispatch(new DocumentProcessed($document->refresh()));
    }

    /**
     * Returns the path of the object this document no longer needs, when its bytes were already stored.
     *
     * @param  array{hash: string, mime: string, size: int}  $facts
     */
    private function commit(Document $document, StorageFile $stored, string $key, array $facts, ?Authenticatable $user): ?string
    {
        $locked = Document::whereKey($document->id)->lockForUpdate()->first();

        if ($locked === null || $locked->status !== DocumentStatus::Processing) {
            return null;
        }

        $type = $document->documentTypeOrFail();
        $existing = $this->storageFiles->findReusable($facts['hash'], $type, $user, $document->documentable, lock: true);

        if ($existing === null) {
            $stored->forceFill([
                'file_hash' => $key,
                'content_hash' => $facts['hash'],
                'mime_type' => $facts['mime'],
                'size_bytes' => $facts['size'],
            ])->save();
            $locked->update(['status' => DocumentStatus::Committed]);

            return null;
        }

        $locked->update(['storage_file_id' => $existing->id, 'status' => DocumentStatus::Committed]);
        UploadSession::where('storage_file_id', $stored->id)->update(['storage_file_id' => $existing->id]);
        $this->storageFiles->delete($stored);

        return $existing->path === $stored->path ? null : $stored->path;
    }

    private function reject(Document $document, StorageFile $stored, string $reason): void
    {
        DB::transaction(function () use ($document) {
            $document->update(['status' => DocumentStatus::Rejected, 'is_latest' => false, 'latest_marker' => null]);

            $this->documents->restorePreviousLatest($document, $document->documentTypeOrFail());
        });

        Storage::disk($stored->disk)->delete($stored->path);

        Event::dispatch(new DocumentRejected($document->refresh(), $reason));
    }
}
