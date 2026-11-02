<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

use Aws\S3\S3Client;
use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use MadeByClowd\Documentable\Services\PrefixNormalizer;
use MadeByClowd\Documentable\Services\PresignClientFactory;
use MadeByClowd\Documentable\Services\Presigner;
use MadeByClowd\Documentable\Services\S3ClientDetector;
use Throwable;

/**
 * Executes doctor diagnostic probes sequentially against an S3 bucket and ensures complete scratch cleanup.
 *
 * @internal
 */
final class DoctorRunner
{
    /** @var list<Probe> */
    private array $probes;

    /**
     * @param  list<Probe>|null  $probes
     */
    public function __construct(
        private readonly Presigner $presigner,
        private readonly PresignClientFactory $presignClientFactory,
        ?array $probes = null,
    ) {
        $this->probes = $probes ?? [
            new PutObjectProbe,
            new PresignedPutProbe,
            new MultipartCreateWithMetadataProbe,
            new MultipartCreateWithoutMetadataProbe,
            new UploadPartProbe,
            new ExposedEtagHeaderProbe,
            new RangedGetProbe,
            new PresignedGetProbe,
            new DeleteAndExistsProbe,
            new LifecycleRulesProbe,
            new ChecksumHeadersProbe,
        ];
    }

    /**
     * Runs all diagnostic probes for the specified disk and guarantees scratch prefix cleanup.
     *
     * @param  (Closure(string): void)|null  $warningCallback
     * @return list<ProbeResult>
     *
     * @throws InvalidArgumentException
     */
    public function run(string $disk, ?Closure $warningCallback = null): array
    {
        $client = S3ClientDetector::client($disk);

        if (! $client instanceof S3Client) {
            throw new InvalidArgumentException("Disk [{$disk}] is not an S3 disk.");
        }

        $bucket = S3ClientDetector::bucket($disk);
        $base = (string) config('documentable.doctor.scratch_prefix', 'documentable-doctor');
        $scratchPrefix = PrefixNormalizer::normalize($base.'/'.bin2hex(random_bytes(6)));

        $context = new DoctorContext(
            disk: $disk,
            bucket: $bucket,
            scratchPrefix: $scratchPrefix,
            internalClient: $client,
            presigner: $this->presigner,
            presignClientFactory: $this->presignClientFactory,
        );

        $results = [];

        try {
            foreach ($this->probes as $probe) {
                $results[] = $probe->run($context);
            }
        } finally {
            $this->cleanup($context, $warningCallback);
        }

        return $results;
    }

    /**
     * Cleans up all objects and incomplete multipart uploads created under the doctor scratch prefix.
     *
     * @param  (Closure(string): void)|null  $warningCallback
     */
    private function cleanup(DoctorContext $context, ?Closure $warningCallback = null): void
    {
        $client = $context->internalClient;
        $bucket = $context->bucket;

        // Abort all tracked multipart uploads
        foreach ($context->openedMultipartUploads as $uploadId => $key) {
            try {
                $client->abortMultipartUpload([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                ]);
            } catch (Throwable) {
                // Ignore cleanup error
            }
        }

        // List and abort any additional incomplete multipart uploads under the scratch prefix
        try {
            $multipartList = $client->listMultipartUploads([
                'Bucket' => $bucket,
                'Prefix' => $context->scratchPrefix,
            ]);

            foreach ($multipartList['Uploads'] ?? [] as $upload) {
                try {
                    $client->abortMultipartUpload([
                        'Bucket' => $bucket,
                        'Key' => $upload['Key'],
                        'UploadId' => $upload['UploadId'],
                    ]);
                } catch (Throwable) {
                    // Ignore cleanup error
                }
            }
        } catch (Throwable) {
            // Some providers (or tokens) do not implement listMultipartUploads
        }

        // Delete all objects created under the scratch prefix
        try {
            $objects = $client->listObjectsV2([
                'Bucket' => $bucket,
                'Prefix' => $context->scratchPrefix,
            ]);

            $keysToDelete = [];
            foreach ($objects['Contents'] ?? [] as $object) {
                $keysToDelete[] = ['Key' => $object['Key']];
            }

            if (! empty($keysToDelete)) {
                $client->deleteObjects([
                    'Bucket' => $bucket,
                    'Delete' => ['Objects' => $keysToDelete],
                ]);
            }
        } catch (Throwable $e) {
            $msg = "Doctor cleanup could not delete scratch objects under [{$context->scratchPrefix}]: {$e->getMessage()}. Please remove them manually.";
            Log::warning($msg);
            if ($warningCallback !== null) {
                $warningCallback($msg);
            }
        }
    }
}
