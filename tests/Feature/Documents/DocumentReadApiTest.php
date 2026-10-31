<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Exceptions\DocumentNotReady;
use MadeByClowd\Documentable\Exceptions\DocumentTooLargeToRead;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentAccessLog;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;

class DocumentReadApiTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected DocumentType $type;

    protected TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        $this->service = app(DocumentService::class);
        $this->type = DocumentType::create([
            'code' => 'REPORT',
            'name' => 'Report',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'reports',
            'allows_multiple' => true,
            'requires_versioning' => false,
        ]);
        $this->owner = TestModel::create(['name' => 'Owner']);
    }

    public function test_read_stream_and_read_contents_bytes_round_trip(): void
    {
        $payload = 'The quick brown fox jumps over the lazy dog.';
        $file = UploadedFile::fake()->createWithContent('sample.txt', $payload);
        $doc = $this->service->upload($file, $this->type, $this->owner);

        $stream = $this->service->readStream($doc);
        $this->assertSame($payload, stream_get_contents($stream));
        fclose($stream);

        $contents = $this->service->readContents($doc);
        $this->assertSame($payload, $contents);
    }

    public function test_not_ready_processing_and_rejected_throw_document_not_ready(): void
    {
        $file = UploadedFile::fake()->createWithContent('sample.txt', 'hello world');
        $doc = $this->service->upload($file, $this->type, $this->owner);

        $doc->update(['status' => DocumentStatus::Processing]);

        $this->expectException(DocumentNotReady::class);
        $this->service->readStream($doc);
    }

    public function test_rejected_status_throws_document_not_ready(): void
    {
        $file = UploadedFile::fake()->createWithContent('sample.txt', 'hello world');
        $doc = $this->service->upload($file, $this->type, $this->owner);

        $doc->update(['status' => DocumentStatus::Rejected]);

        $this->expectException(DocumentNotReady::class);
        $this->service->readContents($doc);
    }

    public function test_missing_storage_file_throws_logic_exception(): void
    {
        $doc = new Document([
            'client_filename' => 'orphan.pdf',
            'status' => DocumentStatus::Committed,
        ]);
        $doc->id = (string) Str::uuid();

        $this->expectException(LogicException::class);
        $this->service->readStream($doc);
    }

    public function test_over_limit_throws_before_reading(): void
    {
        $file = UploadedFile::fake()->createWithContent('large.txt', '1234567890');
        $doc = $this->service->upload($file, $this->type, $this->owner);

        // size_bytes in storageFile is 10. Requesting maxBytes of 5 must refuse before reading.
        try {
            $this->service->readContents($doc, 5);
            $this->fail('Expected DocumentTooLargeToRead exception was not thrown.');
        } catch (DocumentTooLargeToRead $e) {
            $this->assertSame($doc->id, $e->documentId);
            $this->assertSame(10, $e->size);
            $this->assertSame(5, $e->limit);
        }
    }

    public function test_lying_size_bytes_stops_at_limit_and_throws(): void
    {
        $file = UploadedFile::fake()->createWithContent('lying.txt', '123456789012345'); // 15 bytes
        $doc = $this->service->upload($file, $this->type, $this->owner);

        // Tamper storage_file->size_bytes to claim only 5 bytes
        $storageFile = $doc->storageFileOrFail();
        $storageFile->update(['size_bytes' => 5]);
        $doc->refresh();

        // maxBytes = 8. Stored size claims 5 (which is <= 8), but the stream actually has 15 bytes.
        try {
            $this->service->readContents($doc, 8);
            $this->fail('Expected DocumentTooLargeToRead exception was not thrown.');
        } catch (DocumentTooLargeToRead $e) {
            $this->assertSame($doc->id, $e->documentId);
            $this->assertGreaterThan(8, $e->size);
            $this->assertSame(8, $e->limit);
        }
    }

    public function test_failing_disk_throws_runtime_exception_with_disk_and_path(): void
    {
        $file = UploadedFile::fake()->createWithContent('missing.txt', 'content');
        $doc = $this->service->upload($file, $this->type, $this->owner);

        $storageFile = $doc->storageFileOrFail();
        // Delete the physical file from the disk to cause read to fail
        Storage::disk($storageFile->disk)->delete($storageFile->path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($storageFile->disk);
        $this->expectExceptionMessage($storageFile->path);

        $this->service->readStream($doc);
    }

    public function test_access_log_action_read_written_when_enabled(): void
    {
        config()->set('documentable.audit.access_log', true);

        $file = UploadedFile::fake()->createWithContent('logged.txt', 'logged content');
        $doc = $this->service->upload($file, $this->type, $this->owner);

        $this->assertSame(0, DocumentAccessLog::count());

        $stream = $this->service->readStream($doc);
        fclose($stream);

        $this->assertSame(1, DocumentAccessLog::count());
        $this->assertSame([$doc->id], DocumentAccessLog::pluck('document_id')->all());
        $this->assertSame(['read'], DocumentAccessLog::pluck('action')->all());

        $this->service->readContents($doc);

        $this->assertSame(2, DocumentAccessLog::count());
        $this->assertSame(['read', 'read'], DocumentAccessLog::pluck('action')->all());
    }

    public function test_the_default_limit_is_ten_mebibytes_exactly(): void
    {
        $doc = $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', 'abc'), $this->type, $this->owner);

        $doc->storageFile->update(['size_bytes' => 10 * 1024 * 1024]);
        $this->assertSame('abc', $this->service->readContents($doc->fresh()));

        $doc->storageFile->update(['size_bytes' => 10 * 1024 * 1024 + 1]);
        $this->expectException(DocumentTooLargeToRead::class);
        $this->service->readContents($doc->fresh());
    }

    public function test_content_exactly_at_the_limit_is_read_and_one_byte_over_is_refused(): void
    {
        $doc = $this->service->upload(UploadedFile::fake()->createWithContent('a.txt', 'abcd'), $this->type, $this->owner);

        $this->assertSame('abcd', $this->service->readContents($doc, 4));

        $doc->storageFile->update(['size_bytes' => 1]);

        try {
            $this->service->readContents($doc->fresh(), 3);
            $this->fail('Four bytes against a limit of three must be refused.');
        } catch (DocumentTooLargeToRead $e) {
            $this->assertSame(4, $e->size);
            $this->assertSame(3, $e->limit);
            $this->assertStringContainsString($doc->id, $e->getMessage());
        }
    }
}
