<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Tests\TestCase;

class BucketReaperTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  ?string  $versioning  what GetBucketVersioning answers first: 'Enabled', 'Suspended' or null (never versioned)
     */
    protected function setUpDisk(string $disk = 's3_bucket_reaper', array $overrides = [], ?string $versioning = null): void
    {
        if (isset($overrides['handler'])) {
            $overrides['handler']->append(new Result(array_filter(['Status' => $versioning])));
        }

        config()->set("filesystems.disks.{$disk}", array_merge([
            'driver' => 's3',
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'key',
            'secret' => 'secret',
            'request_checksum_calculation' => 'when_required',
        ], $overrides));
    }

    /**
     * Collects the messages the real logger writes at a level, without mocking it (a strict Log mock also trips on the
     * deprecation notices an old SDK logs).
     *
     * @return \Closure(): list<string>
     */
    private function captureLogged(string $level): \Closure
    {
        $messages = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$messages, $level) {
            if ($event->level === $level) {
                $messages[] = $event->message;
            }
        });

        return function () use (&$messages): array {
            return $messages;
        };
    }

    public function test_bucket_reaper_refuses_non_s3_disk(): void
    {
        config()->set('filesystems.disks.local_disk', ['driver' => 'local', 'root' => storage_path()]);

        DocumentType::create([
            'code' => 'LOCAL_TYPE',
            'name' => 'Local Type',
            'max_size_mb' => 10,
            'disk' => 'local_disk',
            'path_prefix' => 'uploads',
        ]);

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Disk [local_disk] is not an S3 disk.')
            ->assertExitCode(1);
    }

    public function test_bucket_reaper_refuses_invalid_path_prefix(): void
    {
        $this->setUpDisk();

        DocumentType::create([
            'code' => 'ROOT_TYPE',
            'name' => 'Root Type',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => '/',
        ]);

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Invalid path prefix [/] for disk [s3_bucket_reaper]')
            ->assertExitCode(1);
    }

    public function test_bucket_reaper_prefix_boundary_does_not_match_sibling_prefixes(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);

        DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        // ListObjectsV2 returns an object under sibling prefix and one under documents/
        $mock->append(new Result([
            'Contents' => [
                [
                    'Key' => 'documents-archive/old.txt',
                    'Size' => 500,
                    'LastModified' => now()->subDays(5),
                ],
                [
                    'Key' => 'documents/orphan.txt',
                    'Size' => 100,
                    'LastModified' => now()->subDays(5),
                ],
            ],
            'IsTruncated' => false,
        ]));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Found orphan: [documents/orphan.txt] (100 bytes)')
            ->doesntExpectOutputToContain('documents-archive')
            ->expectsOutputToContain('Found 1 orphaned object(s) (100 bytes).')
            ->assertExitCode(0);
    }

    public function test_bucket_reaper_young_object_kept(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);

        DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        // Object is 2 hours old, default grace period is 24 hours
        $mock->append(new Result([
            'Contents' => [
                [
                    'Key' => 'documents/young.txt',
                    'Size' => 100,
                    'LastModified' => now()->subHours(2),
                ],
            ],
            'IsTruncated' => false,
        ]));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->doesntExpectOutputToContain('documents/young.txt')
            ->expectsOutputToContain('Found 0 orphaned object(s) (0 bytes).')
            ->assertExitCode(0);
    }

    public function test_bucket_reaper_age_threshold_follows_the_configured_grace_period(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);
        config()->set('documentable.lifecycle.storage_file_grace_hours', 1);

        DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        $mock->append(new Result([
            'Contents' => [
                ['Key' => 'documents/two-hours-old.txt', 'Size' => 100, 'LastModified' => now()->subHours(2)],
            ],
            'IsTruncated' => false,
        ]));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Found 1 orphaned object(s) (100 bytes).')
            ->assertExitCode(0);
    }

    public function test_bucket_sweep_warns_that_deleting_from_a_versioned_bucket_frees_nothing(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock], 'Enabled');
        DocumentType::create(['code' => 'DOCS', 'name' => 'Docs', 'max_size_mb' => 10, 'disk' => 's3_bucket_reaper', 'path_prefix' => 'documents']);
        $mock->append(new Result(['Contents' => [], 'IsTruncated' => false]));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Disk [s3_bucket_reaper] has bucket versioning on: deleting an object only adds a delete marker and frees no storage. Add a lifecycle rule with NoncurrentVersionExpiration to reclaim it.')
            ->assertExitCode(0);
    }

    public function test_bucket_sweep_says_nothing_about_versioning_on_an_unversioned_bucket(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);
        DocumentType::create(['code' => 'DOCS', 'name' => 'Docs', 'max_size_mb' => 10, 'disk' => 's3_bucket_reaper', 'path_prefix' => 'documents']);
        $mock->append(new Result(['Contents' => [], 'IsTruncated' => false]));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->doesntExpectOutputToContain('versioning')
            ->assertExitCode(0);
    }

    public function test_bucket_reaper_referenced_object_kept(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);

        $type = DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        // 1 referenced in storage_files
        StorageFile::create([
            'disk' => 's3_bucket_reaper',
            'path' => 'documents/storage_file.bin',
            'file_hash' => 'hash1',
            'mime_type' => 'text/plain',
            'size_bytes' => 100,
        ]);

        // 1 referenced in upload_sessions
        UploadSession::create([
            'document_type_id' => $type->id,
            'disk' => 's3_bucket_reaper',
            'kind' => UploadKind::Single,
            'client_filename' => 'session.bin',
            'path' => 'documents/session.bin',
            'status' => UploadSessionStatus::Uploaded,
            'expires_at' => now()->addHour(),
        ]);

        $mock->append(new Result([
            'Contents' => [
                [
                    'Key' => 'documents/storage_file.bin',
                    'Size' => 100,
                    'LastModified' => now()->subDays(5),
                ],
                [
                    'Key' => 'documents/session.bin',
                    'Size' => 200,
                    'LastModified' => now()->subDays(5),
                ],
                [
                    'Key' => 'documents/orphan.bin',
                    'Size' => 300,
                    'LastModified' => now()->subDays(5),
                ],
            ],
            'IsTruncated' => false,
        ]));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Found orphan: [documents/orphan.bin] (300 bytes)')
            ->doesntExpectOutputToContain('documents/storage_file.bin')
            ->doesntExpectOutputToContain('documents/session.bin')
            ->expectsOutputToContain('Found 1 orphaned object(s) (300 bytes).')
            ->assertExitCode(0);
    }

    public function test_bucket_reaper_dry_run_deletes_nothing(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);

        DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        $mock->append(new Result([
            'Contents' => [
                [
                    'Key' => 'documents/orphan.bin',
                    'Size' => 150,
                    'LastModified' => now()->subDays(5),
                ],
            ],
            'IsTruncated' => false,
        ]));

        // Dry-run: DeleteObject is not appended to mock handler and not called
        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Found orphan: [documents/orphan.bin] (150 bytes)')
            ->expectsOutputToContain('Found 1 orphaned object(s) (150 bytes). Run with --apply to delete them.')
            ->assertExitCode(0);
    }

    public function test_bucket_reaper_apply_deletes(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);

        DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        $mock->append(new Result([
            'Contents' => [
                [
                    'Key' => 'documents/orphan.bin',
                    'Size' => 150,
                    'LastModified' => now()->subDays(5),
                ],
            ],
            'IsTruncated' => false,
        ]));

        $deletedKey = null;
        $mock->append(function (CommandInterface $cmd) use (&$deletedKey) {
            $deletedKey = $cmd['Key'];

            return new Result;
        });

        $this->artisan('documents:clean-orphaned', ['--bucket' => true, '--apply' => true])
            ->expectsOutputToContain('Deleted orphan: [documents/orphan.bin] (150 bytes)')
            ->expectsOutputToContain('Deleted 1 orphaned object(s) (150 bytes).')
            ->assertExitCode(0);

        $this->assertSame('documents/orphan.bin', $deletedKey);
    }

    public function test_bucket_reaper_pagination_across_multiple_pages(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);

        DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        // Page 1
        $mock->append(new Result([
            'Contents' => [
                [
                    'Key' => 'documents/orphan1.bin',
                    'Size' => 100,
                    'LastModified' => now()->subDays(5),
                ],
            ],
            'IsTruncated' => true,
            'NextContinuationToken' => 'token-page-2',
        ]));

        // Page 2
        $receivedToken = null;
        $mock->append(function (CommandInterface $cmd) use (&$receivedToken) {
            $receivedToken = $cmd['ContinuationToken'] ?? null;

            return new Result([
                'Contents' => [
                    [
                        'Key' => 'documents/orphan2.bin',
                        'Size' => 200,
                        'LastModified' => now()->subDays(5),
                    ],
                ],
                'IsTruncated' => false,
            ]);
        });

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Found orphan: [documents/orphan1.bin] (100 bytes)')
            ->expectsOutputToContain('Found orphan: [documents/orphan2.bin] (200 bytes)')
            ->expectsOutputToContain('Found 2 orphaned object(s) (300 bytes).')
            ->assertExitCode(0);

        $this->assertSame('token-page-2', $receivedToken);
    }

    public function test_bucket_reaper_delete_failure_exits_non_zero(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);

        DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        $mock->append(new Result([
            'Contents' => [
                [
                    'Key' => 'documents/failing.bin',
                    'Size' => 100,
                    'LastModified' => now()->subDays(5),
                ],
            ],
            'IsTruncated' => false,
        ]));

        $cmd = new Command('DeleteObject', ['Bucket' => 'test-bucket', 'Key' => 'documents/failing.bin']);
        $mock->append(new S3Exception('Delete Failed', $cmd, ['code' => 'AccessDenied']));

        $errors = $this->captureLogged('error');

        $this->artisan('documents:clean-orphaned', ['--bucket' => true, '--apply' => true])
            ->expectsOutputToContain('Failed to delete [documents/failing.bin]')
            ->assertExitCode(1);

        $this->assertCount(1, $errors());
        $this->assertStringContainsString('documents/failing.bin', $errors()[0]);
    }

    public function test_bucket_reaper_counts_only_the_objects_it_deleted(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);
        DocumentType::create(['code' => 'DOCS', 'name' => 'Docs', 'max_size_mb' => 10, 'disk' => 's3_bucket_reaper', 'path_prefix' => 'documents']);
        $old = now()->subDays(5);
        $mock->append(new Result(['Contents' => [
            ['Key' => 'documents/a.bin', 'Size' => 10, 'LastModified' => $old],
            ['Key' => 'documents/b.bin', 'Size' => 20, 'LastModified' => $old],
        ], 'IsTruncated' => false]));
        $mock->append(new Result);
        $mock->append(new S3Exception('denied', new Command('DeleteObject'), ['code' => 'AccessDenied']));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true, '--apply' => true])
            ->expectsOutputToContain('Deleted 1 orphaned object(s) (30 bytes).')
            ->assertExitCode(1);

    }

    public function test_bucket_reaper_waits_a_day_by_default_before_calling_an_object_orphaned(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);
        config()->set('documentable.lifecycle', []);
        DocumentType::create(['code' => 'DOCS', 'name' => 'Docs', 'max_size_mb' => 10, 'disk' => 's3_bucket_reaper', 'path_prefix' => 'documents']);
        $mock->append(new Result(['Contents' => [
            ['Key' => 'documents/23-hours.bin', 'Size' => 1, 'LastModified' => now()->subHours(23)],
            ['Key' => 'documents/25-hours.bin', 'Size' => 1, 'LastModified' => now()->subHours(25)],
        ], 'IsTruncated' => false]));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true])
            ->expectsOutputToContain('Found orphan: [documents/25-hours.bin]')
            ->doesntExpectOutputToContain('documents/23-hours.bin')
            ->assertExitCode(0);
    }

    public function test_bucket_reaper_idempotency(): void
    {
        $mock = new MockHandler;
        $this->setUpDisk('s3_bucket_reaper', ['handler' => $mock]);

        DocumentType::create([
            'code' => 'DOCS',
            'name' => 'Docs',
            'max_size_mb' => 10,
            'disk' => 's3_bucket_reaper',
            'path_prefix' => 'documents',
        ]);

        $mock->append(new Result(['Contents' => [], 'IsTruncated' => false]));

        $this->artisan('documents:clean-orphaned', ['--bucket' => true, '--apply' => true])
            ->expectsOutputToContain('Deleted 0 orphaned object(s) (0 bytes).')
            ->assertExitCode(0);
    }

    public function test_bucket_reaper_against_real_rustfs(): void
    {
        $endpoint = env('RUSTFS_ENDPOINT');
        if (! is_string($endpoint) || $endpoint === '' || ! class_exists(S3Client::class)) {
            $this->markTestSkipped('RUSTFS_* is not configured.');
        }

        $bucket = (string) env('RUSTFS_BUCKET', 'documentable-test');

        config(['filesystems.disks.rustfs_reaper' => [
            'driver' => 's3',
            'key' => env('RUSTFS_ACCESS_KEY_ID'),
            'secret' => env('RUSTFS_SECRET_ACCESS_KEY'),
            'region' => env('RUSTFS_REGION', 'us-east-1'),
            'bucket' => $bucket,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'throw' => true,
        ]]);

        $s3 = Storage::disk('rustfs_reaper');

        $docType = DocumentType::create([
            'code' => 'RUSTFS_REAP',
            'name' => 'RustFS Reap',
            'max_size_mb' => 10,
            'disk' => 'rustfs_reaper',
            'path_prefix' => 'rustfs-reap-prefix',
        ]);

        $prefix = 'rustfs-reap-prefix/';
        $siblingPrefix = 'rustfs-reap-prefix-sibling/';

        $refPath = $prefix.'referenced.txt';
        $sessionPath = $prefix.'session.txt';
        $orphanPath = $prefix.'orphan.txt';
        $siblingPath = $siblingPrefix.'sibling.txt';

        $s3->put($refPath, 'referenced content');
        $s3->put($sessionPath, 'session content');
        $s3->put($orphanPath, 'orphan content');
        $s3->put($siblingPath, 'sibling content');

        // Create database references
        StorageFile::create([
            'disk' => 'rustfs_reaper',
            'path' => $refPath,
            'file_hash' => 'hash-ref',
            'mime_type' => 'text/plain',
            'size_bytes' => 18,
        ]);

        UploadSession::create([
            'document_type_id' => $docType->id,
            'disk' => 'rustfs_reaper',
            'kind' => UploadKind::Single,
            'client_filename' => 'session.txt',
            'path' => $sessionPath,
            'status' => UploadSessionStatus::Uploaded,
            'expires_at' => now()->addHour(),
        ]);

        // Dry-run with --hours=0 (all files are older than 0 hours)
        $this->artisan('documents:clean-orphaned', [
            '--bucket' => true,
            '--hours' => 0,
        ])
            ->expectsOutputToContain("Found orphan: [{$orphanPath}]")
            ->doesntExpectOutputToContain($refPath)
            ->doesntExpectOutputToContain($sessionPath)
            ->doesntExpectOutputToContain($siblingPath)
            ->expectsOutputToContain('Found 1 orphaned object(s)')
            ->assertExitCode(0);

        // Verify orphan still exists after dry-run
        $this->assertTrue($s3->exists($orphanPath));

        // Now run with --apply
        $this->artisan('documents:clean-orphaned', [
            '--bucket' => true,
            '--apply' => true,
            '--hours' => 0,
        ])
            ->expectsOutputToContain("Deleted orphan: [{$orphanPath}]")
            ->expectsOutputToContain('Deleted 1 orphaned object(s)')
            ->assertExitCode(0);

        // Verify orphan is gone, but referenced, session, and sibling remain
        $this->assertFalse($s3->exists($orphanPath));
        $this->assertTrue($s3->exists($refPath));
        $this->assertTrue($s3->exists($sessionPath));
        $this->assertTrue($s3->exists($siblingPath));

        // Cleanup test objects
        $s3->delete($refPath);
        $s3->delete($sessionPath);
        $s3->delete($siblingPath);
    }
}
