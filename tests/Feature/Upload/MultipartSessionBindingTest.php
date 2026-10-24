<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\MultipartUpload;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * A multipart session is created for one document type (and therefore one disk, prefix, size limit and mime
 * allow-list). It used to be completable under any other active type, which sidestepped the limits of the type
 * it was started with: start under a permissive type, finish as a restrictive one.
 */
class MultipartSessionBindingTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('filesystems.disks.other_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
        Storage::fake('other_disk');
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        FakeMultipartUploadDriver::reset();

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function makeType(string $code, string $disk = 'test_disk'): DocumentType
    {
        return DocumentType::create(['code' => $code, 'name' => $code, 'max_size_mb' => 10, 'disk' => $disk, 'path_prefix' => $code]);
    }

    public function test_completing_a_session_as_a_different_type_is_rejected(): void
    {
        $started = $this->makeType('STARTED');
        $other = $this->makeType('OTHER');
        $owner = TestModel::create(['name' => 'owner']);
        $session = $this->service->initiateMultipartUpload('f.bin', $started, 'user-1');
        FakeMultipartUploadDriver::uploadPart($session['upload_id'], 1, 'bytes');

        try {
            $this->service->completeMultipartUpload($session['path'], $session['upload_id'], 'user-1', $other, $owner, 'f.bin');
            $this->fail('A session must only complete under the type it was started with.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document_type_id', $e->errors());
        }

        $this->assertSame(0, Document::count());
        $this->assertSame(1, MultipartUpload::count());
    }

    public function test_completing_a_session_as_a_type_on_another_disk_is_rejected(): void
    {
        $started = $this->makeType('STARTED');
        $elsewhere = $this->makeType('ELSEWHERE', 'other_disk');
        $owner = TestModel::create(['name' => 'owner']);
        $session = $this->service->initiateMultipartUpload('f.bin', $started, 'user-1');
        FakeMultipartUploadDriver::uploadPart($session['upload_id'], 1, 'bytes');

        $this->expectException(ValidationException::class);

        $this->service->completeMultipartUpload($session['path'], $session['upload_id'], 'user-1', $elsewhere, $owner, 'f.bin');
    }

    public function test_the_other_session_calls_are_bound_to_the_type_too(): void
    {
        $started = $this->makeType('STARTED');
        $other = $this->makeType('OTHER');
        $session = $this->service->initiateMultipartUpload('f.bin', $started, 'user-1');

        foreach ([
            fn () => $this->service->generatePartUploadUrl($session['path'], $session['upload_id'], 'user-1', 1, $other),
            fn () => $this->service->listPartsForSession($session['path'], $session['upload_id'], 'user-1', $other),
            fn () => $this->service->abortMultipartUpload($session['path'], $session['upload_id'], 'user-1', $other),
        ] as $call) {
            try {
                $call();
                $this->fail('The call must be rejected for a different type.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('document_type_id', $e->errors());
            }
        }

        $this->assertSame(1, MultipartUpload::count());
    }

    public function test_the_matching_type_still_completes(): void
    {
        $type = $this->makeType('STARTED');
        $owner = TestModel::create(['name' => 'owner']);
        $session = $this->service->initiateMultipartUpload('f.bin', $type, 'user-1');
        FakeMultipartUploadDriver::uploadPart($session['upload_id'], 1, 'bytes');

        $document = $this->service->completeMultipartUpload($session['path'], $session['upload_id'], 'user-1', $type, $owner, 'f.bin');

        $this->assertSame($type->id, $document->document_type_id);
    }
}
