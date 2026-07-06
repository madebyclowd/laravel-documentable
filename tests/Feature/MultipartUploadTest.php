<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Exceptions\UnsupportedMultipartDriverException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class MultipartUploadTest extends TestCase
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

    protected function initiateAndUploadParts(DocumentType $type, string $userId, array $partBodies): array
    {
        $session = $this->service->initiateMultipartUpload('big.bin', $type, $userId);

        $clientParts = [];
        foreach ($partBodies as $i => $body) {
            $partNumber = $i + 1;
            $etag = FakeMultipartUploadDriver::uploadPart($session['upload_id'], $partNumber, $body);
            $clientParts[] = ['PartNumber' => $partNumber, 'ETag' => $etag];
        }

        return [$session, $clientParts];
    }

    public function test_server_authoritative_strategy_completes_and_verifies_integrity(): void
    {
        config()->set('documentable.multipart.etag_strategy', 'server-authoritative');

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        [$session] = $this->initiateAndUploadParts($type, 'user-1', ['hello ', 'world']);

        $expectedHash = hash('sha256', 'hello world');

        $document = $this->service->completeMultipartUpload(
            $session['path'],
            $session['upload_id'],
            'user-1',
            $type,
            $owner,
            'big.bin',
            null, // client parts ignored under server-authoritative
            $expectedHash
        );

        $this->assertSame('big.bin', $document->client_filename);
        $this->assertSame('hello world', Storage::disk('test_disk')->get($document->storageFile->path));
        $this->assertSame(0, MultipartUpload::count());
    }

    public function test_client_strategy_completes_using_client_supplied_etags(): void
    {
        config()->set('documentable.multipart.etag_strategy', 'client');

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        [$session, $clientParts] = $this->initiateAndUploadParts($type, 'user-1', ['foo', 'bar']);

        $document = $this->service->completeMultipartUpload(
            $session['path'],
            $session['upload_id'],
            'user-1',
            $type,
            $owner,
            'foobar.bin',
            $clientParts,
            hash('sha256', 'foobar')
        );

        $this->assertSame('foobar', Storage::disk('test_disk')->get($document->storageFile->path));
    }

    public function test_client_strategy_rejects_reconciliation_mismatch_against_actual_uploaded_parts(): void
    {
        config()->set('documentable.multipart.etag_strategy', 'client');

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        [$session] = $this->initiateAndUploadParts($type, 'user-1', ['part-one', 'part-two']);

        // Client claims only part 1 was uploaded — truncated relative to what's
        // actually on the provider (bugs.md #7 regression).
        $forgedParts = [['PartNumber' => 1, 'ETag' => '"forged"']];

        $this->expectException(ValidationException::class);

        $this->service->completeMultipartUpload(
            $session['path'],
            $session['upload_id'],
            'user-1',
            $type,
            $owner,
            'x.bin',
            $forgedParts,
            null
        );
    }

    public function test_multipart_complete_rejects_oversized_assembled_file(): void
    {
        $type = $this->makeType(['max_size_mb' => 0]);
        $owner = TestModel::create(['name' => 'owner']);

        [$session] = $this->initiateAndUploadParts($type, 'user-1', ['too big for a zero-byte limit']);

        $this->expectException(ValidationException::class);

        try {
            $this->service->completeMultipartUpload(
                $session['path'],
                $session['upload_id'],
                'user-1',
                $type,
                $owner,
                'x.bin',
                null
            );
        } finally {
            $this->assertFalse(Storage::disk('test_disk')->exists($session['path']));
        }
    }

    public function test_multipart_complete_rejects_disallowed_mime(): void
    {
        $type = $this->makeType(['allowed_mimes' => ['application/pdf']]);
        $owner = TestModel::create(['name' => 'owner']);

        [$session] = $this->initiateAndUploadParts($type, 'user-1', ['plain text content, not a pdf']);

        $this->expectException(ValidationException::class);

        $this->service->completeMultipartUpload(
            $session['path'],
            $session['upload_id'],
            'user-1',
            $type,
            $owner,
            'x.txt',
            null
        );
    }

    public function test_direct_upload_finalize_rejects_oversized_and_disallowed_mime_same_as_multipart(): void
    {
        $type = $this->makeType(['max_size_mb' => 0]);
        $owner = TestModel::create(['name' => 'owner']);

        $presigned = $this->service->createPresignedUpload($type, 'x.bin');
        Storage::disk('test_disk')->put($presigned['path'], 'oversized-content');

        $this->expectException(ValidationException::class);

        try {
            $this->service->finalizeDirectUpload($presigned['path'], $type, $owner, 'x.bin');
        } finally {
            $this->assertFalse(Storage::disk('test_disk')->exists($presigned['path']));
        }
    }

    public function test_direct_upload_finalize_succeeds_for_small_file_under_threshold(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $presigned = $this->service->createPresignedUpload($type, 'x.bin');
        Storage::disk('test_disk')->put($presigned['path'], 'small content');

        $document = $this->service->finalizeDirectUpload(
            $presigned['path'],
            $type,
            $owner,
            'small.txt',
            hash('sha256', 'small content')
        );

        $this->assertSame('small.txt', $document->client_filename);
        $this->assertSame('small content', Storage::disk('test_disk')->get($document->storageFile->path));
    }

    public function test_direct_upload_dedup_deletes_redundant_duplicate_blob(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $first = $this->service->createPresignedUpload($type, 'a.txt');
        Storage::disk('test_disk')->put($first['path'], 'same content');
        $firstDocument = $this->service->finalizeDirectUpload($first['path'], $type, $owner, 'a.txt');

        $second = $this->service->createPresignedUpload($type, 'b.txt');
        Storage::disk('test_disk')->put($second['path'], 'same content');
        $secondDocument = $this->service->finalizeDirectUpload($second['path'], $type, $owner, 'b.txt');

        $this->assertSame($firstDocument->storage_file_id, $secondDocument->storage_file_id);
        $this->assertTrue(Storage::disk('test_disk')->exists($first['path']));
        $this->assertFalse(Storage::disk('test_disk')->exists($second['path']));
    }

    public function test_ownership_check_rejects_part_url_complete_and_abort_from_different_user(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $session = $this->service->initiateMultipartUpload('f.bin', $type, 'user-1');

        $this->expectException(ValidationException::class);
        $this->service->generatePartUploadUrl($session['path'], $session['upload_id'], 'user-2', 1, $type);
    }

    public function test_ownership_check_rejects_complete_from_different_user(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        [$session] = $this->initiateAndUploadParts($type, 'user-1', ['data']);

        $this->expectException(ValidationException::class);
        $this->service->completeMultipartUpload($session['path'], $session['upload_id'], 'user-2', $type, $owner, 'f.bin');
    }

    public function test_ownership_check_rejects_abort_from_different_user(): void
    {
        $type = $this->makeType();

        $session = $this->service->initiateMultipartUpload('f.bin', $type, 'user-1');

        $this->expectException(ValidationException::class);
        $this->service->abortMultipartUpload($session['path'], $session['upload_id'], 'user-2', $type);
    }

    public function test_abort_removes_session_and_calls_driver_abort(): void
    {
        $type = $this->makeType();

        $session = $this->service->initiateMultipartUpload('f.bin', $type, 'user-1');
        $this->assertSame(1, MultipartUpload::count());

        $this->service->abortMultipartUpload($session['path'], $session['upload_id'], 'user-1', $type);

        $this->assertSame(0, MultipartUpload::count());
    }

    public function test_stale_but_not_yet_expired_session_still_completes(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        [$session] = $this->initiateAndUploadParts($type, 'user-1', ['data']);

        // Simulate a session past its TTL — expiry enforcement is phase 4's
        // reaper job, not this phase's; completing must still work regardless
        // of how "stale" expires_at looks.
        MultipartUpload::where('path', $session['path'])->update(['expires_at' => now()->subDays(30)]);

        $document = $this->service->completeMultipartUpload(
            $session['path'],
            $session['upload_id'],
            'user-1',
            $type,
            $owner,
            'f.bin'
        );

        $this->assertInstanceOf(Document::class, $document);
    }

    public function test_resolving_multipart_driver_for_unmapped_disk_throws_clear_exception(): void
    {
        config()->set('filesystems.disks.unmapped_disk', ['driver' => 'sftp']);
        $type = $this->makeType(['disk' => 'unmapped_disk']);

        $this->expectException(UnsupportedMultipartDriverException::class);

        $this->service->initiateMultipartUpload('f.bin', $type, 'user-1');
    }

    public function test_list_parts_for_session_returns_parts_actually_uploaded(): void
    {
        $type = $this->makeType();

        [$session, $clientParts] = $this->initiateAndUploadParts($type, 'user-1', ['hello ', 'world']);

        $parts = $this->service->listPartsForSession($session['path'], $session['upload_id'], 'user-1', $type);

        $this->assertSame(
            collect($clientParts)->pluck('PartNumber')->all(),
            collect($parts)->pluck('PartNumber')->all()
        );
    }

    public function test_list_parts_for_session_rejects_a_different_owner(): void
    {
        $type = $this->makeType();

        [$session] = $this->initiateAndUploadParts($type, 'user-1', ['data']);

        $this->expectException(ValidationException::class);

        $this->service->listPartsForSession($session['path'], $session['upload_id'], 'user-2', $type);
    }

    public function test_multipart_session_status_reports_exists_for_an_active_session(): void
    {
        $type = $this->makeType();

        $session = $this->service->initiateMultipartUpload('f.bin', $type, 'user-1');

        $status = $this->service->multipartSessionStatus($session['path'], $session['upload_id'], 'user-1');

        $this->assertTrue($status['exists']);
        $this->assertNotNull($status['expires_at']);
        $this->assertSame('test_disk', $status['disk']);
    }

    public function test_multipart_session_status_reports_not_exists_for_a_gone_session(): void
    {
        $status = $this->service->multipartSessionStatus('nowhere/gone.bin', 'not-a-real-upload-id', 'user-1');

        $this->assertSame(['exists' => false, 'expires_at' => null, 'disk' => null], $status);
    }

    public function test_multipart_session_status_does_not_leak_existence_of_another_users_session(): void
    {
        $type = $this->makeType();

        $session = $this->service->initiateMultipartUpload('f.bin', $type, 'user-1');

        $status = $this->service->multipartSessionStatus($session['path'], $session['upload_id'], 'user-2');

        $this->assertFalse($status['exists']);
    }
}
