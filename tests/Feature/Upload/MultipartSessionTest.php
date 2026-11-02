<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\UnsupportedMultipartDriverException;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * The multipart orchestration on top of a session: part reconciliation per etag strategy, integrity and size
 * checks on the assembled object, and the provider driver lookup.
 */
class MultipartSessionTest extends TestCase
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

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'BIG_FILE',
            'name' => 'Big File',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'uploads',
        ], $overrides));
    }

    /**
     * @param  list<string>  $partBodies
     * @return array{0: UploadSession, 1: list<array{PartNumber: int, ETag: string}>}
     */
    protected function initiateAndUploadParts(DocumentType $type, string $userId, array $partBodies): array
    {
        $session = $this->service->initiateMultipartUpload($type, 'big.bin', $userId);

        $clientParts = [];
        foreach ($partBodies as $i => $body) {
            $partNumber = $i + 1;
            $clientParts[] = ['PartNumber' => $partNumber, 'ETag' => FakeMultipartUploadDriver::uploadPart($session->upload_id, $partNumber, $body)];
        }

        return [$session, $clientParts];
    }

    public function test_server_authoritative_strategy_completes_and_verifies_integrity(): void
    {
        config()->set('documentable.multipart.etag_strategy', 'server-authoritative');

        [$session] = $this->initiateAndUploadParts($this->makeType(), 'user-1', ['hello ', 'world']);

        // Client parts are ignored under server-authoritative.
        $uploaded = $this->service->completeMultipartUpload($session, null, hash('sha256', 'hello world'));
        $document = $this->service->attach($uploaded, TestModel::create(['name' => 'owner']));

        $this->assertSame('hello world', Storage::disk('test_disk')->get($document->storageFile->path));
        $this->assertSame(hash('sha256', 'hello world'), $document->storageFile->content_hash);
        $this->assertTrue($document->metadata['mime_type'] === 'text/plain');
    }

    public function test_client_strategy_completes_using_client_supplied_etags(): void
    {
        config()->set('documentable.multipart.etag_strategy', 'client');

        [$session, $clientParts] = $this->initiateAndUploadParts($this->makeType(), 'user-1', ['foo', 'bar']);

        $uploaded = $this->service->completeMultipartUpload($session, $clientParts, hash('sha256', 'foobar'));
        $document = $this->service->attach($uploaded, TestModel::create(['name' => 'owner']));

        $this->assertSame('foobar', Storage::disk('test_disk')->get($document->storageFile->path));
    }

    public function test_client_strategy_rejects_reconciliation_mismatch_against_the_actual_uploaded_parts(): void
    {
        config()->set('documentable.multipart.etag_strategy', 'client');

        [$session] = $this->initiateAndUploadParts($this->makeType(), 'user-1', ['part-one', 'part-two']);

        // The client claims only part 1 landed: truncated against what is on the provider.
        $forgedParts = [['PartNumber' => 1, 'ETag' => '"forged"']];

        $this->expectException(ValidationException::class);

        $this->service->completeMultipartUpload($session, $forgedParts);
    }

    public function test_completing_rejects_an_oversized_assembled_file_and_removes_it(): void
    {
        [$session] = $this->initiateAndUploadParts($this->makeType(['max_size_mb' => 0]), 'user-1', ['too big for a zero-byte limit']);

        try {
            $this->service->completeMultipartUpload($session);
            $this->fail('Expected the oversized object to be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertFalse(Storage::disk('test_disk')->exists($session->path));
        $this->assertSame(UploadSessionStatus::Aborted, $session->fresh()->status);
    }

    public function test_an_unmapped_disk_driver_fails_with_a_clear_exception(): void
    {
        config()->set('filesystems.disks.unmapped_disk', ['driver' => 'sftp']);

        $this->expectException(UnsupportedMultipartDriverException::class);

        $this->service->initiateMultipartUpload($this->makeType(['disk' => 'unmapped_disk']), 'f.bin', 'user-1');
    }

    public function test_listing_parts_returns_the_parts_that_actually_landed(): void
    {
        [$session] = $this->initiateAndUploadParts($this->makeType(), 'user-1', ['aaa', 'bbb']);

        $parts = $this->service->listPartsForSession($session);

        $this->assertSame([1, 2], array_column($parts, 'PartNumber'));
    }

    public function test_part_urls_are_refused_once_the_session_has_ended(): void
    {
        [$session] = $this->initiateAndUploadParts($this->makeType(), 'user-1', ['aaa']);
        $this->service->abortUploadSession($session);

        $this->expectException(ValidationException::class);

        $this->service->generatePartUploadUrl($session->fresh(), 1);
    }

    public function test_completing_twice_is_idempotent(): void
    {
        [$session] = $this->initiateAndUploadParts($this->makeType(), 'user-1', ['hello world']);

        $first = $this->service->completeMultipartUpload($session);
        $second = $this->service->completeMultipartUpload($first);

        $this->assertSame($first->object_hash, $second->object_hash);
        $this->assertSame(UploadSessionStatus::Uploaded, $second->status);
    }

    public function test_a_single_kind_session_cannot_be_driven_as_multipart(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();
        $single = $this->service->initiatePresignedUpload($this->makeType(), 'a.txt', 'user-1')['session'];

        $this->expectException(ValidationException::class);

        $this->service->generatePartUploadUrl($single, 1);
    }
}
