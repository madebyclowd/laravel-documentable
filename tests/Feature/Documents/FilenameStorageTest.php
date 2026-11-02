<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * `client_filename` is written once, normalized, so every consumer that renders it gets a safe value. What the
 * client actually sent is kept in the document's metadata when it differs.
 */
class FilenameStorageTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        Storage::fake('test_disk');
        FakeMultipartUploadDriver::reset();

        $this->service = $this->app->make(DocumentService::class);
    }

    private function type(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'docs', 'allows_multiple' => true,
        ], $overrides));
    }

    public function test_a_server_upload_stores_the_normalized_name_and_keeps_the_original(): void
    {
        $document = $this->service->upload(
            UploadedFile::fake()->createWithContent('..\\..\\evil/plan  v2.txt', 'body'),
            $this->type(),
            TestModel::create(['name' => 'owner'])
        );

        $this->assertSame('plan v2.txt', $document->client_filename);
        // Symfony already drops the client's directory part, so what reaches the package is the last segment.
        $this->assertSame('plan  v2.txt', $document->metadata['original_filename']);
    }

    public function test_a_session_upload_stores_the_normalized_name(): void
    {
        $session = $this->service->initiateMultipartUpload($this->type(), "C:\\fakepath\\scan\t1.txt", '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'body');
        $uploaded = $this->service->completeMultipartUpload($session);

        $document = $this->service->attach($uploaded, TestModel::create(['name' => 'owner']));

        $this->assertSame('scan1.txt', $document->client_filename);
        $this->assertSame("C:\\fakepath\\scan\t1.txt", $document->metadata['original_filename']);
    }

    public function test_an_already_clean_name_adds_no_metadata(): void
    {
        $document = $this->service->upload(
            UploadedFile::fake()->createWithContent('clean.txt', 'body'),
            $this->type(),
            TestModel::create(['name' => 'owner'])
        );

        $this->assertSame('clean.txt', $document->client_filename);
        $this->assertArrayNotHasKey('original_filename', $document->metadata);
    }

    public function test_the_extension_rule_judges_the_normalized_name(): void
    {
        $type = $this->type(['allowed_extensions' => ['txt']]);

        $this->service->upload(UploadedFile::fake()->createWithContent("plan.txt. \x00", 'body'), $type, TestModel::create(['name' => 'owner']));

        $this->expectException(ValidationException::class);

        $this->service->upload(UploadedFile::fake()->createWithContent('plan.txt.exe', 'body'), $type, TestModel::create(['name' => 'owner']));
    }
}
