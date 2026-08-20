<?php

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Exceptions\DiskDoesNotSupportTemporaryUrlsException;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/v2.0.0-feedback.md #1 — getUrl() used to let
 * Storage's generic RuntimeException surface unexplained on a disk without temporaryUrl()
 * support. See docs/implementations/v2.1.0/phase-14-getUrl-disk-driver-fallback.md.
 */
class GetUrlDiskDriverFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected string $plainLocalRoot;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // A real 'local' driver disk, deliberately NOT created via Storage::fake() —
        // Storage::fake() auto-registers a buildTemporaryUrlsUsing() callback, which
        // would hide exactly the failure mode this test exercises.
        $this->plainLocalRoot = sys_get_temp_dir().'/documentable-plain-local-'.uniqid();
        File::ensureDirectoryExists($this->plainLocalRoot);
        config()->set('filesystems.disks.plain_local', [
            'driver' => 'local',
            'root' => $this->plainLocalRoot,
        ]);

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->plainLocalRoot);

        parent::tearDown();
    }

    protected function makeType(): DocumentType
    {
        return DocumentType::create([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'plain_local',
            'path_prefix' => 'docs',
            'requires_versioning' => true,
        ]);
    }

    public function test_get_url_throws_actionable_exception_on_disk_without_temporary_url_support(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $this->expectException(DiskDoesNotSupportTemporaryUrlsException::class);
        $this->expectExceptionMessage('Disk [plain_local] does not support temporary URLs');

        $this->service->getUrl($document, now()->addMinutes(5));
    }

    public function test_get_url_still_succeeds_when_disk_has_a_temporary_url_callback(): void
    {
        Storage::disk('plain_local')->buildTemporaryUrlsUsing(
            fn ($path, $expiration) => 'https://example.test/'.$path
        );

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload(UploadedFile::fake()->create('a.txt', 1), $type, $owner);

        $url = $this->service->getUrl($document, now()->addMinutes(5));

        $this->assertStringStartsWith('https://example.test/', $url);
    }
}
