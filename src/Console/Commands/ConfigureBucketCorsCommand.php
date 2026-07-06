<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Sibling to ConfigureBucketLifecycleCommand: both the direct-PUT and multipart part
 * uploads have the browser PUT bytes cross-origin straight to the bucket, which requires
 * a CORS policy the package can't assume is already there (docs/feedbacks/v2.0.0-feedback.md
 * #5). Not run automatically — mutating bucket-level config should be an explicit,
 * confirmed action, not a side effect of composer install.
 */
class ConfigureBucketCorsCommand extends Command
{
    protected $signature = 'documents:configure-bucket-cors
        {disk : The filesystem disk to configure}
        {--origin=* : Allowed origin(s) for cross-origin PUT requests (repeatable, e.g. --origin=https://app.example.com)}
        {--verify : After applying, perform a live presigned-PUT smoke test against the bucket}';

    protected $description = "Configure the bucket's CORS policy for direct-PUT and multipart part uploads.";

    public function handle(): int
    {
        $disk = $this->argument('disk');
        $origins = $this->option('origin');

        if (empty($origins)) {
            $this->error('At least one --origin is required — a wildcard default would be unsafe to apply silently.');

            return self::FAILURE;
        }

        /** @var S3Client $client */
        $client = Storage::disk($disk)->getClient();
        $bucket = config("filesystems.disks.{$disk}.bucket");
        $exposeEtag = config('documentable.multipart.etag_strategy', 'server-authoritative') === 'client';

        $client->putBucketCors([
            'Bucket' => $bucket,
            'CORSConfiguration' => [
                'CORSRules' => [
                    [
                        'AllowedOrigins' => $origins,
                        'AllowedMethods' => ['GET', 'PUT'],
                        'AllowedHeaders' => ['*'],
                        'ExposeHeaders' => $exposeEtag ? ['ETag'] : [],
                        'MaxAgeSeconds' => 3600,
                    ],
                ],
            ],
        ]);

        $this->info(
            "Configured CORS on disk [{$disk}], bucket [{$bucket}], origins: ".implode(', ', $origins).
            ($exposeEtag ? ' (ExposeHeaders: ETag, per etag_strategy=client)' : '')
        );

        if ($this->option('verify')) {
            return $this->verify($disk);
        }

        return self::SUCCESS;
    }

    protected function verify(string $disk): int
    {
        $path = 'documentable-cors-smoke-test-'.uniqid().'.txt';
        $upload = Storage::disk($disk)->temporaryUploadUrl(now()->addMinutes(5), $path);

        $response = Http::withBody('cors-smoke-test', 'text/plain')->put($upload['url']);

        Storage::disk($disk)->delete($path);

        if ($response->successful()) {
            $this->info('Smoke test PUT succeeded — CORS/credentials are usable from a server context. Browser-origin behavior still depends on the exact --origin match.');

            return self::SUCCESS;
        }

        $this->error("Smoke test PUT failed: HTTP {$response->status()}.");

        return self::FAILURE;
    }
}
