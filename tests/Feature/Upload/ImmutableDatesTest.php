<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;

/**
 * An app may call `Date::use(CarbonImmutable::class)`, which makes `now()` return an immutable date. Nothing in
 * the package may type against the concrete `Illuminate\Support\Carbon`, or every upload throws a TypeError.
 */
class ImmutableDatesTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        Date::use(CarbonImmutable::class);

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        Storage::fake('test_disk');
        FakeMultipartUploadDriver::reset();

        $this->type = DocumentType::create([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'requires_versioning' => true,
        ]);
        $this->service = $this->app->make(DocumentService::class);
    }

    protected function tearDown(): void
    {
        Date::use(Carbon::class);

        parent::tearDown();
    }

    public function test_staging_an_upload_works_when_the_app_uses_immutable_dates(): void
    {
        $file = UploadedFile::fake()->createWithContent('a.txt', 'hello');

        $session = $this->service->stageUpload($file, $this->type, '42');

        $this->assertTrue($session->expires_at->isFuture());
    }

    public function test_initiating_a_multipart_upload_works_when_the_app_uses_immutable_dates(): void
    {
        $session = $this->service->initiateMultipartUpload($this->type, 'big.bin', '42', new UploadSessionData(sizeBytes: 11));

        $this->assertTrue($session->expires_at->isFuture());
    }

    public function test_the_orphan_sweep_runs_when_the_app_uses_immutable_dates(): void
    {
        $this->artisan('documents:clean-orphaned')->assertExitCode(0);
        $this->artisan('documents:clean-orphaned', ['--hours' => 1])->assertExitCode(0);
    }
}
