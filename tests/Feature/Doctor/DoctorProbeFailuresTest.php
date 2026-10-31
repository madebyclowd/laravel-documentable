<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Doctor;

use Aws\Command;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use MadeByClowd\Documentable\Doctor\DeleteAndExistsProbe;
use MadeByClowd\Documentable\Doctor\DoctorContext;
use MadeByClowd\Documentable\Doctor\DoctorRunner;
use MadeByClowd\Documentable\Doctor\ExposedEtagHeaderProbe;
use MadeByClowd\Documentable\Doctor\MultipartCreateWithMetadataProbe;
use MadeByClowd\Documentable\Doctor\MultipartCreateWithoutMetadataProbe;
use MadeByClowd\Documentable\Doctor\PresignedGetProbe;
use MadeByClowd\Documentable\Doctor\PresignedPutProbe;
use MadeByClowd\Documentable\Doctor\Probe;
use MadeByClowd\Documentable\Doctor\ProbeResult;
use MadeByClowd\Documentable\Doctor\ProbeStatus;
use MadeByClowd\Documentable\Doctor\PutObjectProbe;
use MadeByClowd\Documentable\Doctor\RangedGetProbe;
use MadeByClowd\Documentable\Doctor\UploadPartProbe;
use MadeByClowd\Documentable\Services\PresignClientFactory;
use MadeByClowd\Documentable\Services\Presigner;
use MadeByClowd\Documentable\Services\S3ClientDetector;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Each probe runs on its own against a scripted S3 client, so every way a provider can fail is checked: the probe
 * must report a failure with a fix, and an upload it opened must stay tracked so the runner aborts it.
 */
class DoctorProbeFailuresTest extends TestCase
{
    private const DISK = 's3_probe_failures';

    private const SCRATCH = 'documentable-doctor/abc/';

    private MockHandler $mock;

    /** @var list<string> names of the S3 commands the probe sent, in order */
    private array $sent = [];

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
    }

    /**
     * Script the client: each command name maps to a Result, or to a Throwable that is thrown. Unlisted commands
     * answer with an empty Result.
     *
     * @param  array<string, Result|\Throwable>  $answers
     */
    private function script(array $answers): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->mock->append(function (CommandInterface $command) use ($answers) {
                $this->sent[] = $command->getName();
                $answer = $answers[$command->getName()] ?? new Result;

                return $answer instanceof \Throwable ? throw $answer : $answer;
            });
        }
    }

    private function context(): DoctorContext
    {
        return new DoctorContext(
            disk: self::DISK,
            bucket: 'test-bucket',
            scratchPrefix: self::SCRATCH,
            internalClient: S3ClientDetector::client(self::DISK),
            presigner: $this->app->make(Presigner::class),
            presignClientFactory: $this->app->make(PresignClientFactory::class),
        );
    }

    private function denied(string $command): S3Exception
    {
        return new S3Exception('denied', new Command($command), ['code' => 'AccessDenied', 'response' => new Response(403)]);
    }

    private function runProbe(Probe $probe, ?DoctorContext $context = null): ProbeResult
    {
        return $probe->run($context ?? $this->context());
    }

    private function assertFailsWithFix(ProbeResult $result, string $fixContains): void
    {
        $this->assertSame(ProbeStatus::Fail, $result->status, $result->message);
        $this->assertNotNull($result->fix);
        $this->assertStringContainsString($fixContains, (string) $result->fix);
    }

    public function test_put_object_probe_reports_a_denied_write(): void
    {
        $this->script(['PutObject' => $this->denied('PutObject')]);

        $this->assertFailsWithFix($this->runProbe(new PutObjectProbe), 's3:PutObject');
    }

    public function test_presigned_put_probe_reports_a_rejected_upload(): void
    {
        Http::fake(['*' => Http::response('<Error>SignatureDoesNotMatch</Error>', 403)]);

        $result = $this->runProbe(new PresignedPutProbe);

        $this->assertFailsWithFix($result, 'presign_endpoint');
        $this->assertStringContainsString('HTTP 403', $result->message);
    }

    public function test_presigned_put_probe_reports_an_unreachable_endpoint(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $result = $this->runProbe(new PresignedPutProbe);

        $this->assertFailsWithFix($result, 'accessible from the client');
        $this->assertStringContainsString('connection refused', $result->message);
    }

    /**
     * @return array<string, array{class-string<Probe>, string}>
     */
    public static function multipartCreateProbes(): array
    {
        return [
            'with metadata' => [MultipartCreateWithMetadataProbe::class, 'DOCUMENTABLE_MULTIPART_OBJECT_METADATA=false'],
            'without metadata' => [MultipartCreateWithoutMetadataProbe::class, 'multipart uploads'],
        ];
    }

    /**
     * @param  class-string<Probe>  $probe
     */
    #[DataProvider('multipartCreateProbes')]
    public function test_multipart_create_probes_report_a_rejected_create(string $probe, string $fix): void
    {
        $this->script(['CreateMultipartUpload' => $this->denied('CreateMultipartUpload')]);

        $this->assertFailsWithFix($this->runProbe(new $probe), $fix);
    }

    /**
     * @param  class-string<Probe>  $probe
     */
    #[DataProvider('multipartCreateProbes')]
    public function test_multipart_create_probes_leave_an_upload_that_cannot_be_aborted_tracked_for_the_runner(string $probe, string $fix): void
    {
        $this->script([
            'CreateMultipartUpload' => new Result(['UploadId' => 'mp-1']),
            'AbortMultipartUpload' => $this->denied('AbortMultipartUpload'),
        ]);
        $context = $this->context();

        $result = $this->runProbe(new $probe, $context);

        $this->assertFailsWithFix($result, $fix);
        $this->assertArrayHasKey('mp-1', $context->openedMultipartUploads, 'The runner aborts what stays tracked.');
    }

    /**
     * @param  class-string<Probe>  $probe
     */
    #[DataProvider('multipartCreateProbes')]
    public function test_multipart_create_probes_untrack_an_upload_they_aborted(string $probe, string $fix): void
    {
        $this->script(['CreateMultipartUpload' => new Result(['UploadId' => 'mp-1'])]);
        $context = $this->context();

        $result = $this->runProbe(new $probe, $context);

        $this->assertSame(ProbeStatus::Pass, $result->status);
        $this->assertSame([], $context->openedMultipartUploads);
        $this->assertSame(['CreateMultipartUpload', 'AbortMultipartUpload'], $this->sent);
    }

    /**
     * @return array<string, array{class-string<Probe>, ?array<string, string>}>
     */
    public static function metadataSent(): array
    {
        return [
            'with metadata' => [MultipartCreateWithMetadataProbe::class, ['doctor' => 'probe']],
            'without metadata' => [MultipartCreateWithoutMetadataProbe::class, null],
        ];
    }

    /**
     * @param  class-string<Probe>  $probe
     * @param  ?array<string, string>  $expected
     */
    #[DataProvider('metadataSent')]
    public function test_each_create_probe_sends_exactly_the_metadata_it_is_named_for(string $probe, ?array $expected): void
    {
        $sent = 'not sent';
        $this->mock->append(function (CommandInterface $command) use (&$sent) {
            $sent = $command['Metadata'] ?? null;

            return new Result(['UploadId' => 'mp-1']);
        });
        $this->mock->append(new Result);

        $this->runProbe(new $probe);

        $this->assertSame($expected, $sent);
    }

    public function test_upload_part_probe_reports_a_rejected_part(): void
    {
        $this->script(['CreateMultipartUpload' => new Result(['UploadId' => 'mp-1'])]);
        Http::fake(['*' => Http::response('NotImplemented', 501)]);
        $context = $this->context();

        $result = $this->runProbe(new UploadPartProbe, $context);

        $this->assertFailsWithFix($result, 'CORS');
        $this->assertStringContainsString('part 1 failed with HTTP 501', $result->message);
        $this->assertArrayHasKey('mp-1', $context->openedMultipartUploads);
    }

    public function test_upload_part_probe_reports_a_rejected_second_part(): void
    {
        $this->script(['CreateMultipartUpload' => new Result(['UploadId' => 'mp-1'])]);
        Http::fake(['*' => Http::sequence()->push('', 200, ['ETag' => '"e1"'])->push('boom', 500)]);

        $result = $this->runProbe(new UploadPartProbe);

        $this->assertFailsWithFix($result, 'CORS');
        $this->assertStringContainsString('part 2 failed with HTTP 500', $result->message);
    }

    public function test_upload_part_probe_reports_a_failed_completion(): void
    {
        $this->script([
            'CreateMultipartUpload' => new Result(['UploadId' => 'mp-1']),
            'ListParts' => new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"e1"'], ['PartNumber' => 2, 'ETag' => '"e2"']]]),
            'CompleteMultipartUpload' => new S3Exception('EntityTooSmall', new Command('CompleteMultipartUpload'), ['code' => 'EntityTooSmall']),
        ]);
        Http::fake(['*' => Http::response('', 200, ['ETag' => '"e"'])]);
        $context = $this->context();

        $result = $this->runProbe(new UploadPartProbe, $context);

        $this->assertFailsWithFix($result, 'multipart upload actions');
        $this->assertArrayHasKey('mp-1', $context->openedMultipartUploads);
    }

    public function test_upload_part_probe_passes_with_equal_parts_except_the_last(): void
    {
        $this->script([
            'CreateMultipartUpload' => new Result(['UploadId' => 'mp-1']),
            'ListParts' => new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"e1"'], ['PartNumber' => 2, 'ETag' => '"e2"']]]),
        ]);
        Http::fake(['*' => Http::response('', 200, ['ETag' => '"e"'])]);
        $context = $this->context();

        $result = $this->runProbe(new UploadPartProbe, $context);

        $this->assertSame(ProbeStatus::Pass, $result->status, $result->message);
        $this->assertSame([], $context->openedMultipartUploads);
        $this->assertSame(['CreateMultipartUpload', 'ListParts', 'CompleteMultipartUpload'], $this->sent);
    }

    public function test_etag_probe_reuses_the_headers_of_the_part_upload(): void
    {
        $context = $this->context();
        $context->lastPartHeaders = ['Etag' => ['"e1"']];

        $this->assertSame(ProbeStatus::Pass, $this->runProbe(new ExposedEtagHeaderProbe, $context)->status);
        $this->assertSame([], $this->sent, 'A part upload already ran, so the probe asks the bucket for nothing.');
    }

    public function test_etag_probe_uploads_its_own_part_when_none_ran_before(): void
    {
        $this->script(['CreateMultipartUpload' => new Result(['UploadId' => 'mp-1'])]);
        Http::fake(['*' => Http::response('', 200, ['ETag' => '"e1"'])]);
        $context = $this->context();

        $result = $this->runProbe(new ExposedEtagHeaderProbe, $context);

        $this->assertSame(ProbeStatus::Pass, $result->status, $result->message);
        $this->assertSame([], $context->openedMultipartUploads);
    }

    public function test_etag_probe_reports_a_response_without_an_etag(): void
    {
        $context = $this->context();
        $context->lastPartHeaders = ['Content-Length' => ['0']];

        $this->assertFailsWithFix($this->runProbe(new ExposedEtagHeaderProbe, $context), 'documents:configure-bucket-cors');
    }

    public function test_etag_probe_reports_a_failure_to_open_its_own_upload_instead_of_a_missing_header(): void
    {
        $this->script(['CreateMultipartUpload' => $this->denied('CreateMultipartUpload')]);

        $result = $this->runProbe(new ExposedEtagHeaderProbe);

        $this->assertSame(ProbeStatus::Fail, $result->status);
        $this->assertStringContainsString('could not check', strtolower($result->message));
    }

    public function test_etag_probe_reports_a_rejected_small_part_upload(): void
    {
        $this->script(['CreateMultipartUpload' => new Result(['UploadId' => 'mp-1'])]);
        Http::fake(['*' => Http::response('Forbidden', 403)]);
        $context = $this->context();

        $result = $this->runProbe(new ExposedEtagHeaderProbe, $context);

        $this->assertSame(ProbeStatus::Fail, $result->status);
        $this->assertStringContainsString('The part upload returned HTTP 403', $result->message);
        $this->assertArrayHasKey('mp-1', $context->openedMultipartUploads);
    }

    public function test_presigned_get_probe_reports_a_missing_object(): void
    {
        $this->script([]);
        Http::fake(['*' => Http::response('NoSuchKey', 404)]);

        $result = $this->runProbe(new PresignedGetProbe);

        $this->assertFailsWithFix($result, 's3:GetObject');
        $this->assertStringContainsString('HTTP 404', $result->message);
    }

    public function test_presigned_get_probe_reports_a_changed_payload(): void
    {
        $this->script([]);
        Http::fake(['*' => Http::response('an error page from a cache', 200)]);

        $this->assertFailsWithFix($this->runProbe(new PresignedGetProbe), 'proxy or CDN caches');
    }

    public function test_presigned_get_probe_reports_a_failed_seed_write(): void
    {
        $this->script(['PutObject' => $this->denied('PutObject')]);

        $this->assertFailsWithFix($this->runProbe(new PresignedGetProbe), 's3:GetObject');
    }

    public function test_ranged_get_probe_reports_a_provider_that_returns_the_wrong_bytes(): void
    {
        $this->script(['GetObject' => new Result(['Body' => 'abcd'])]);

        $result = $this->runProbe(new RangedGetProbe);

        $this->assertFailsWithFix($result, 'honors the HTTP Range header');
        $this->assertStringContainsString("Expected 4 bytes '0123', got 'abcd'", $result->message);
    }

    public function test_ranged_get_probe_reports_a_provider_that_ignores_the_range(): void
    {
        $this->script(['GetObject' => new Result(['Body' => '0123456789'])]);

        $this->assertFailsWithFix($this->runProbe(new RangedGetProbe), 'byte range requests');
    }

    public function test_ranged_get_probe_reports_a_failed_read(): void
    {
        $this->script(['GetObject' => $this->denied('GetObject')]);

        $this->assertFailsWithFix($this->runProbe(new RangedGetProbe), 'byte range');
    }

    public function test_delete_probe_reports_an_object_that_survives_its_deletion(): void
    {
        $this->script(['HeadObject' => new Result(['ContentLength' => 11])]);

        $this->assertFailsWithFix($this->runProbe(new DeleteAndExistsProbe), 's3:DeleteObject');
    }

    public function test_delete_probe_reports_a_failed_write(): void
    {
        $this->script(['PutObject' => $this->denied('PutObject')]);

        $this->assertFailsWithFix($this->runProbe(new DeleteAndExistsProbe), 's3:DeleteObject');
    }

    public function test_delete_probe_notes_a_disk_that_throws(): void
    {
        config()->set('filesystems.disks.'.self::DISK.'.throw', true);
        $this->script(['HeadObject' => new S3Exception('404', new Command('HeadObject'), ['code' => 'NotFound', 'response' => new Response(404)])]);

        $result = $this->runProbe(new DeleteAndExistsProbe);

        $this->assertSame(ProbeStatus::Pass, $result->status, $result->message);
        $this->assertStringContainsString('throw is true', $result->message);
    }

    /**
     * Runs the doctor's own probe list against a client that records every object key it is sent.
     *
     * @return array{results: list<ProbeResult>, keys: list<string>}
     */
    private function fullRun(): array
    {
        $keys = [];
        for ($i = 0; $i < 60; $i++) {
            $this->mock->append(function (CommandInterface $command) use (&$keys) {
                if (isset($command['Key'])) {
                    $keys[] = $command['Key'];
                }

                return match ($command->getName()) {
                    'CreateMultipartUpload' => new Result(['UploadId' => 'mp-1']),
                    'GetObject' => new Result(['Body' => '0123']),
                    'ListParts' => new Result(['Parts' => [['PartNumber' => 1, 'ETag' => '"e1"'], ['PartNumber' => 2, 'ETag' => '"e2"']]]),
                    'HeadObject' => throw new S3Exception('404', $command, ['code' => 'NotFound', 'response' => new Response(404)]),
                    default => new Result,
                };
            });
        }
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response('doctor-presigned-get-payload', 200)
            : Http::response('', 200, ['ETag' => '"e"']));

        return ['results' => $this->app->make(DoctorRunner::class)->run(self::DISK), 'keys' => $keys];
    }

    public function test_a_full_run_writes_and_deletes_only_under_the_scratch_prefix(): void
    {
        ['keys' => $keys] = $this->fullRun();

        $this->assertNotEmpty($keys);
        foreach ($keys as $key) {
            $this->assertStringStartsWith('documentable-doctor/', $key, "A probe touched [{$key}] outside the scratch prefix.");
        }
    }

    public function test_the_doctor_runs_every_probe_in_a_fixed_order(): void
    {
        ['results' => $results] = $this->fullRun();

        $this->assertSame([
            'PutObject', 'PresignedPut', 'MultipartCreateWithMetadata', 'MultipartCreateWithoutMetadata', 'UploadPart',
            'ExposedEtagHeader', 'RangedGet', 'PresignedGet', 'DeleteAndExists', 'LifecycleRules', 'ChecksumHeaders',
        ], array_map(fn (ProbeResult $r) => $r->name, $results));
    }

    public function test_the_part_probe_sends_a_five_mib_part_then_a_small_last_part(): void
    {
        $this->script(['CreateMultipartUpload' => new Result(['UploadId' => 'mp-1'])]);
        Http::fake(['*' => Http::response('', 200, ['ETag' => '"e"'])]);

        $this->runProbe(new UploadPartProbe);

        $sizes = Http::recorded()->map(fn (array $pair) => strlen($pair[0]->body()))->all();
        $this->assertSame([5 * 1024 * 1024, 1024], $sizes);
    }
}
