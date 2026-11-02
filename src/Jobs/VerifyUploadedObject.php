<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use MadeByClowd\Documentable\Services\ProcessingDocumentVerifier;

/**
 * Finishes the verification of a large upload: hashes the object, runs the scanner, then promotes the
 * `processing` document or rejects it. All the logic is in {@see ProcessingDocumentVerifier}; this class is the
 * queue envelope.
 *
 * Safe to run more than once, so a retry or a duplicate delivery does no harm. A storage or scanner outage
 * throws and is retried with backoff; the object is only ever deleted for a verdict about its content.
 *
 * @internal
 */
final class VerifyUploadedObject implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public readonly string $documentId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(ProcessingDocumentVerifier $verifier): void
    {
        $verifier->verify($this->documentId);
    }
}
