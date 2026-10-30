<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Console\Commands;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use MadeByClowd\Documentable\Services\PrefixNormalizer;
use MadeByClowd\Documentable\Services\S3ClientDetector;

/**
 * Configures bucket lifecycle rules for multipart abort and doctor scratch expiration.
 */
class ConfigureBucketLifecycleCommand extends Command
{
    protected $signature = 'documents:configure-bucket-lifecycle
        {disk : The filesystem disk to configure}
        {--days=3 : Days after initiation before an incomplete multipart upload is aborted}';

    protected $description = "Configure the bucket's lifecycle rules for a disk (multipart abort and doctor scratch expiration).";

    public function handle(): int
    {
        $diskArgument = $this->argument('disk');
        $disk = is_string($diskArgument) ? $diskArgument : '';
        $days = (int) $this->option('days');

        $client = S3ClientDetector::client($disk);

        if (! $client instanceof S3Client) {
            $this->error("Disk [{$disk}] is not an S3 disk.");

            return self::FAILURE;
        }

        $bucket = S3ClientDetector::bucket($disk);
        $scratchPrefix = PrefixNormalizer::normalize((string) config('documentable.doctor.scratch_prefix', 'documentable-doctor'));

        $existingRules = [];

        try {
            $result = $client->getBucketLifecycleConfiguration(['Bucket' => $bucket]);
            $existingRules = $result['Rules'] ?? [];
        } catch (S3Exception $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchLifecycleConfiguration') {
                throw $e;
            }
        }

        $keptRules = array_values(array_filter(
            $existingRules,
            fn (array $rule) => ! str_starts_with((string) ($rule['ID'] ?? ''), 'documentable-')
        ));

        $newRules = [
            [
                'ID' => 'documentable-abort-incomplete-multipart-uploads',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => ''],
                'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => $days],
            ],
            [
                'ID' => 'documentable-expire-doctor-scratch',
                'Status' => 'Enabled',
                'Filter' => ['Prefix' => $scratchPrefix],
                'Expiration' => ['Days' => 1],
            ],
        ];

        $mergedRules = array_merge($keptRules, $newRules);

        $client->putBucketLifecycleConfiguration([
            'Bucket' => $bucket,
            'LifecycleConfiguration' => [
                'Rules' => $mergedRules,
            ],
        ]);

        $this->info("Configured lifecycle rules on disk [{$disk}], bucket [{$bucket}].");

        return self::SUCCESS;
    }
}
