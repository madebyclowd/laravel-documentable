<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Defense in depth per best-practices.md §2: the app-level reaper alone is
 * not the enterprise standard, it must be paired with a bucket-native
 * backstop that still fires even if the reaper's schedule dies or a deploy
 * drops it. Not run automatically — mutating bucket-level config should be
 * an explicit, confirmed action, not a side effect of composer install.
 */
class ConfigureBucketLifecycleCommand extends Command
{
    protected $signature = 'documents:configure-bucket-lifecycle
        {disk : The filesystem disk to configure}
        {--days=3 : Days after initiation before an incomplete multipart upload is aborted}';

    protected $description = "Configure the bucket's AbortIncompleteMultipartUpload lifecycle rule for a disk.";

    public function handle(): int
    {
        $disk = $this->argument('disk');
        $days = (int) $this->option('days');

        /** @var S3Client $client */
        $client = Storage::disk($disk)->getClient();
        $bucket = config("filesystems.disks.{$disk}.bucket");

        $client->putBucketLifecycleConfiguration([
            'Bucket' => $bucket,
            'LifecycleConfiguration' => [
                'Rules' => [
                    [
                        'ID' => 'documentable-abort-incomplete-multipart-uploads',
                        'Status' => 'Enabled',
                        'Filter' => ['Prefix' => ''],
                        'AbortIncompleteMultipartUpload' => ['DaysAfterInitiation' => $days],
                    ],
                ],
            ],
        ]);

        $this->info("Configured AbortIncompleteMultipartUpload ({$days} days) on disk [{$disk}], bucket [{$bucket}].");

        return self::SUCCESS;
    }
}
