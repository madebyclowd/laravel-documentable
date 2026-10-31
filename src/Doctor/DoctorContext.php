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

    /**
     * Opens a multipart upload and tracks it. A probe that fails afterwards leaves it tracked, and the runner
     * aborts whatever is still tracked, so no probe needs cleanup code of its own.
     *
     * @param  array<string, mixed>  $options  further CreateMultipartUpload parameters
     */
    public function openMultipart(string $key, array $options = []): string
    {
        $result = $this->internalClient->createMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key] + $options);
        $uploadId = (string) $result['UploadId'];

        $this->trackMultipart($uploadId, $key);

        return $uploadId;
    }

    /**
     * Aborts a tracked upload. It stays tracked if the abort fails.
     */
    public function abortMultipart(string $uploadId): void
    {
        $this->internalClient->abortMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $this->openedMultipartUploads[$uploadId],
            'UploadId' => $uploadId,
        ]);

        $this->untrackMultipart($uploadId);
    }

    public function trackMultipart(string $uploadId, string $key): void
    {
        $this->openedMultipartUploads[$uploadId] = $key;
    }

    public function untrackMultipart(string $uploadId): void
    {
        unset($this->openedMultipartUploads[$uploadId]);
    }
}
