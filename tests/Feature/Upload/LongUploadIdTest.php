<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Cloudflare R2 returns 343 character upload ids and storage keys can reach 1,024 bytes, but the session
 * table declared both as varchar(255). PostgreSQL (and MySQL in strict mode) refused the insert, so large
 * uploads failed at `initiate`. SQLite never enforces the length, so only the engine matrix catches this.
 */
class LongUploadIdTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        FakeMultipartUploadDriver::reset();
        FakeMultipartUploadDriver::$longIds = true;

        $this->app->bind(GeneratesStoragePath::class, fn () => new class implements GeneratesStoragePath
        {
            public function generate(DocumentType $type, string $filename): string
            {
                return 'tenant/'.implode('/', array_fill(0, 10, str_repeat('a', 99))).'/'.$filename;
            }
        });

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function makeType(): DocumentType
    {
        return DocumentType::create([
            'code' => 'BIG',
            'name' => 'Big',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
        ]);
    }

    public function test_a_session_with_a_long_upload_id_and_path_round_trips_through_the_whole_flow(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $session = $this->service->initiateMultipartUpload($type, 'f.bin', 'user-1');

        $fresh = UploadSession::findOrFail($session->id);
        $this->assertSame(512, strlen($fresh->upload_id));
        $this->assertGreaterThan(1000, strlen($fresh->path));

        FakeMultipartUploadDriver::uploadPart($fresh->upload_id, 1, 'hello world');

        $parts = $this->service->listPartsForSession($fresh);
        $this->assertSame([1], array_column($parts, 'PartNumber'));

        $uploaded = $this->service->completeMultipartUpload($fresh, null, hash('sha256', 'hello world'));
        $document = $this->service->attach($uploaded, $owner);

        $this->assertSame($fresh->path, $document->storageFile->path);
    }

    public function test_a_long_session_can_be_aborted(): void
    {
        $session = $this->service->initiateMultipartUpload($this->makeType(), 'f.bin', 'user-1');

        $this->service->abortUploadSession($session);

        $this->assertSame(UploadSessionStatus::Aborted, $session->fresh()->status);
    }
}
