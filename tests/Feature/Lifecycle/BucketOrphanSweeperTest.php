<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\BucketSweepRefused;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\BucketOrphanSweeper;
use MadeByClowd\Documentable\Services\OrphanedObject;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BucketOrphanSweeperTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 's3_sweeper';

    /** @var list<CommandInterface> every command the mocked S3 client received, in order */
    private array $commands = [];

    private MockHandler $mock;

    private DateTimeImmutable $cutoff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock = new MockHandler;
        config()->set('filesystems.disks.'.self::DISK, [
            'driver' => 's3',
            'bucket' => 'test-bucket',
            'region' => 'us-east-1',
            'key' => 'key',
            'secret' => 'secret',
            'request_checksum_calculation' => 'when_required',
            'handler' => $this->mock,
        ]);

        $this->cutoff = new DateTimeImmutable('2026-01-01 12:00:00');
    }

    private function type(string $code, string $prefix, string $disk = self::DISK): DocumentType
    {
        return DocumentType::create(['code' => $code, 'name' => $code, 'max_size_mb' => 10, 'disk' => $disk, 'path_prefix' => $prefix]);
    }

    /**
     * Queue one S3 answer and remember the command that consumed it.
     */
    private function answer(Result $result): void
    {
        $this->mock->append(function (CommandInterface $command) use ($result) {
            $this->commands[] = $command;

            return $result;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $contents
     */
    private function listing(array $contents, ?string $nextToken = null): Result
    {
        return new Result([
            'Contents' => $contents,
            'IsTruncated' => $nextToken !== null,
            'NextContinuationToken' => $nextToken,
        ]);
    }

    private function old(string $key, int|string|null $size = 10): array
    {
        return array_filter(['Key' => $key, 'Size' => $size, 'LastModified' => $this->cutoff->modify('-1 day')], fn ($v) => $v !== null);
    }

    /**
     * @return list<string>
     */
    private function orphanKeys(): array
    {
        return array_map(fn (OrphanedObject $o) => $o->key, iterator_to_array(app(BucketOrphanSweeper::class)->orphans($this->cutoff), false));
    }

    public function test_every_distinct_prefix_is_scanned(): void
    {
        $this->type('A', 'alpha');
        $this->type('B', 'beta');
        $this->answer($this->listing([$this->old('alpha/one.bin')]));
        $this->answer($this->listing([$this->old('beta/two.bin')]));

        $this->assertEqualsCanonicalizing(['alpha/one.bin', 'beta/two.bin'], $this->orphanKeys());
        $this->assertCount(2, $this->commands);
    }

    public function test_the_first_listing_has_no_continuation_token_and_the_next_one_carries_it(): void
    {
        $this->type('A', 'alpha');
        $this->answer($this->listing([$this->old('alpha/one.bin')], 'token-2'));
        $this->answer($this->listing([$this->old('alpha/two.bin')]));

        $this->assertSame(['alpha/one.bin', 'alpha/two.bin'], $this->orphanKeys());
        $this->assertArrayNotHasKey('ContinuationToken', $this->commands[0]->toArray());
        $this->assertSame('token-2', $this->commands[1]['ContinuationToken']);
        $this->assertSame('alpha/', $this->commands[0]['Prefix']);
    }

    public function test_an_object_modified_exactly_at_the_cutoff_is_old_enough_but_a_newer_one_is_not(): void
    {
        $this->type('A', 'alpha');
        $this->answer($this->listing([
            ['Key' => 'alpha/at-cutoff.bin', 'Size' => 1, 'LastModified' => $this->cutoff],
            ['Key' => 'alpha/just-after.bin', 'Size' => 1, 'LastModified' => $this->cutoff->modify('+1 second')],
            ['Key' => 'alpha/no-time.bin', 'Size' => 1],
        ]));

        $this->assertSame(['alpha/at-cutoff.bin'], $this->orphanKeys());
    }

    public function test_the_size_is_an_integer_and_defaults_to_zero(): void
    {
        $this->type('A', 'alpha');
        $this->answer($this->listing([$this->old('alpha/numeric.bin', '150'), $this->old('alpha/unsized.bin', null)]));

        $sizes = [];
        foreach (app(BucketOrphanSweeper::class)->orphans($this->cutoff) as $orphan) {
            $sizes[$orphan->key] = $orphan->size;
        }

        $this->assertSame(['alpha/numeric.bin' => 150, 'alpha/unsized.bin' => 0], $sizes);
    }

    public function test_a_key_outside_the_prefix_is_ignored_even_if_the_listing_returns_it(): void
    {
        $this->type('A', 'alpha');
        $this->answer($this->listing([$this->old('alpha/in.bin'), $this->old('alpha-archive/out.bin'), $this->old('beta/out.bin')]));

        $this->assertSame(['alpha/in.bin'], $this->orphanKeys());
    }

    /**
     * @return array<string, array{UploadSessionStatus, bool}>
     */
    public static function sessionStatuses(): array
    {
        return [
            'initiated is live' => [UploadSessionStatus::Initiated, true],
            'uploaded is live' => [UploadSessionStatus::Uploaded, true],
            'attached is live' => [UploadSessionStatus::Attached, true],
            'expired is not' => [UploadSessionStatus::Expired, false],
            'aborted is not' => [UploadSessionStatus::Aborted, false],
        ];
    }

    #[DataProvider('sessionStatuses')]
    public function test_only_live_sessions_keep_their_object(UploadSessionStatus $status, bool $kept): void
    {
        $type = $this->type('A', 'alpha');
        UploadSession::create([
            'document_type_id' => $type->id,
            'disk' => self::DISK,
            'kind' => UploadKind::Single,
            'client_filename' => 'f.bin',
            'path' => 'alpha/session.bin',
            'status' => $status,
            'expires_at' => now()->addHour(),
        ]);
        $this->answer($this->listing([$this->old('alpha/session.bin')]));

        $this->assertSame($kept ? [] : ['alpha/session.bin'], $this->orphanKeys());
    }

    public function test_one_unsafe_prefix_refuses_the_whole_sweep_before_anything_is_listed(): void
    {
        $this->type('A', 'alpha');
        $this->type('B', '/');
        $this->answer($this->listing([$this->old('alpha/one.bin')]));

        try {
            $this->orphanKeys();
            $this->fail('A prefix that reaches the bucket root must refuse the sweep.');
        } catch (BucketSweepRefused $e) {
            $this->assertStringContainsString('Invalid path prefix [/]', $e->getMessage());
        }

        $this->assertSame([], $this->commands, 'No listing may start before every prefix is validated.');
    }

    public function test_a_document_type_on_a_non_s3_disk_refuses_the_sweep(): void
    {
        config()->set('filesystems.disks.plain_local', ['driver' => 'local', 'root' => storage_path('app')]);
        $this->type('A', 'alpha', 'plain_local');

        $this->expectException(BucketSweepRefused::class);
        $this->expectExceptionMessage('Disk [plain_local] is not an S3 disk.');

        $this->orphanKeys();
    }

    /**
     * @return array<string, array{?string, list<string>}>
     */
    public static function versioningStates(): array
    {
        return [
            'enabled' => ['Enabled', [self::DISK]],
            'suspended keeps old versions too' => ['Suspended', [self::DISK]],
            'never versioned' => [null, []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('versioningStates')]
    public function test_it_reports_the_disks_whose_bucket_keeps_old_versions(?string $status, array $expected): void
    {
        $this->type('A', 'alpha');
        $this->type('B', 'beta');
        $this->answer(new Result(array_filter(['Status' => $status])));

        $this->assertSame($expected, app(BucketOrphanSweeper::class)->versionedDisks());
        $this->assertCount(1, $this->commands, 'Two prefixes on one disk ask the bucket once.');
        $this->assertSame('test-bucket', $this->commands[0]['Bucket']);
    }

    public function test_a_disk_that_cannot_answer_does_not_inherit_the_previous_disks_state(): void
    {
        config()->set('filesystems.disks.s3_second', config('filesystems.disks.'.self::DISK));
        $this->type('A', 'alpha');
        $this->type('B', 'beta', 's3_second');
        $this->answer(new Result(['Status' => 'Enabled']));
        $this->mock->append(new S3Exception('denied', new Command('GetBucketVersioning'), ['code' => 'AccessDenied']));

        $this->assertSame([self::DISK], app(BucketOrphanSweeper::class)->versionedDisks());
    }

    public function test_the_bucket_is_asked_about_versioning_once_per_disk(): void
    {
        $this->type('A', 'alpha');
        $this->type('B', 'beta');
        $this->answer(new Result(['Status' => 'Enabled']));
        $this->answer(new Result(['Status' => 'Enabled']));

        $disks = app(BucketOrphanSweeper::class)->versionedDisks();

        $this->assertSame([self::DISK], $disks, 'A second ask would list the disk twice.');
        $this->assertCount(1, $this->commands);
    }

    public function test_a_token_that_cannot_read_the_versioning_state_reports_nothing(): void
    {
        $this->type('A', 'alpha');
        $this->mock->append(new S3Exception('denied', new Command('GetBucketVersioning'), ['code' => 'AccessDenied']));

        $this->assertSame([], app(BucketOrphanSweeper::class)->versionedDisks());
    }

    public function test_delete_removes_exactly_the_orphans_key_from_its_bucket(): void
    {
        $this->answer(new Result);

        app(BucketOrphanSweeper::class)->delete(new OrphanedObject(self::DISK, 'alpha/gone.bin', 5));

        $this->assertSame('DeleteObject', $this->commands[0]->getName());
        $this->assertSame('test-bucket', $this->commands[0]['Bucket']);
        $this->assertSame('alpha/gone.bin', $this->commands[0]['Key']);
    }

    public function test_delete_refuses_an_orphan_that_is_not_on_an_s3_disk(): void
    {
        config()->set('filesystems.disks.plain_local', ['driver' => 'local', 'root' => storage_path('app')]);

        $this->expectException(BucketSweepRefused::class);

        app(BucketOrphanSweeper::class)->delete(new OrphanedObject('plain_local', 'alpha/x.bin', 1));
    }
}
