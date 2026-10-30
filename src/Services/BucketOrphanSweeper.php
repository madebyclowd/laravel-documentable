<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Aws\S3\S3Client;
use DateTimeInterface;
use Generator;
use InvalidArgumentException;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\BucketSweepRefused;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * Finds objects in the bucket that the database no longer knows about, such as what a rolled-back request or a
 * legacy key left behind. It only looks under the path prefix of a configured document type, and never reads a
 * whole bucket into memory.
 *
 * @internal
 */
final class BucketOrphanSweeper
{
    /**
     * @return Generator<int, OrphanedObject>
     *
     * @throws BucketSweepRefused before the first object when a disk or prefix cannot be swept safely
     */
    public function orphans(DateTimeInterface $olderThan): Generator
    {
        foreach ($this->scopes() as ['disk' => $disk, 'prefix' => $prefix]) {
            $client = S3ClientDetector::client($disk) ?? throw BucketSweepRefused::notAnS3Disk($disk);

            foreach ($this->oldObjects($client, S3ClientDetector::bucket($disk), $prefix, $olderThan) as $page) {
                $referenced = $this->referencedPaths($disk, array_column($page, 'Key'));

                foreach ($page as $object) {
                    if (! isset($referenced[$object['Key']])) {
                        yield new OrphanedObject($disk, $object['Key'], $object['Size']);
                    }
                }
            }
        }
    }

    public function delete(OrphanedObject $orphan): void
    {
        $client = S3ClientDetector::client($orphan->disk) ?? throw BucketSweepRefused::notAnS3Disk($orphan->disk);

        $client->deleteObject([
            'Bucket' => S3ClientDetector::bucket($orphan->disk),
            'Key' => $orphan->key,
        ]);
    }

    /**
     * Every distinct disk and normalized prefix, all checked before any listing starts.
     *
     * @return list<array{disk: string, prefix: string}>
     */
    private function scopes(): array
    {
        $scopes = [];

        foreach (DocumentType::query()->select(['disk', 'path_prefix'])->distinct()->get() as $type) {
            $disk = (string) $type->disk;

            if (! S3ClientDetector::isS3($disk)) {
                throw BucketSweepRefused::notAnS3Disk($disk);
            }

            try {
                $scopes[] = ['disk' => $disk, 'prefix' => PrefixNormalizer::normalize((string) $type->path_prefix)];
            } catch (InvalidArgumentException $e) {
                throw BucketSweepRefused::unsafePrefix((string) $type->path_prefix, $disk, $e->getMessage());
            }
        }

        return $scopes;
    }

    /**
     * One listing page at a time, keeping only objects last modified before the cutoff. An object with no
     * modification time is treated as new and kept.
     *
     * @return Generator<int, list<array{Key: string, Size: int}>>
     */
    private function oldObjects(S3Client $client, string $bucket, string $prefix, DateTimeInterface $olderThan): Generator
    {
        $token = null;

        do {
            $result = $client->listObjectsV2(array_filter([
                'Bucket' => $bucket,
                'Prefix' => $prefix,
                'ContinuationToken' => $token,
            ]));

            $page = [];
            foreach ($result['Contents'] ?? [] as $object) {
                $modified = $object['LastModified'] ?? null;

                if ($modified instanceof DateTimeInterface && $modified <= $olderThan && str_starts_with((string) $object['Key'], $prefix)) {
                    $page[] = ['Key' => (string) $object['Key'], 'Size' => (int) ($object['Size'] ?? 0)];
                }
            }

            if ($page !== []) {
                yield $page;
            }

            $token = ! empty($result['IsTruncated']) ? ($result['NextContinuationToken'] ?? null) : null;
        } while ($token !== null);
    }

    /**
     * The given paths that a stored file or a live upload session still points at, as a lookup set.
     *
     * @param  list<string>  $paths
     * @return array<string, int>
     */
    private function referencedPaths(string $disk, array $paths): array
    {
        $liveStatuses = [UploadSessionStatus::Initiated, UploadSessionStatus::Uploaded, UploadSessionStatus::Attached];

        $stored = StorageFile::query()->where('disk', $disk)->whereIn('path', $paths)->pluck('path');
        $inFlight = UploadSession::query()->where('disk', $disk)->whereIn('status', $liveStatuses)->whereIn('path', $paths)->pluck('path');

        return array_flip($stored->merge($inFlight)->all());
    }
}
