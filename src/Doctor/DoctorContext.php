<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Aws\S3\S3Client;
use MadeByClowd\Documentable\Services\PresignClientFactory;
use MadeByClowd\Documentable\Services\Presigner;

/**
 * Shared context and resources across doctor probes for a single run.
 *
 * @internal
 */
final class DoctorContext
{
    /** @var array<string, string> uploadId => key */
    public array $openedMultipartUploads = [];

    /** @var array<string, list<string>> */
    public array $lastPartHeaders = [];

    public function __construct(
        public readonly string $disk,
        public readonly string $bucket,
        public readonly string $scratchPrefix,
        public readonly S3Client $internalClient,
        public readonly Presigner $presigner,
        public readonly PresignClientFactory $presignClientFactory,
    ) {}

    public function trackMultipart(string $uploadId, string $key): void
    {
        $this->openedMultipartUploads[$uploadId] = $key;
    }

    public function untrackMultipart(string $uploadId): void
    {
        unset($this->openedMultipartUploads[$uploadId]);
    }
}
