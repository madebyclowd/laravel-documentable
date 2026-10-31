<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Testing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Exceptions\MultipartPartTooSmallException;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\TestCase;

class FakeMultipartUploadDriverTest extends TestCase
{
    use RefreshDatabase;

    private FakeMultipartUploadDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        FakeMultipartUploadDriver::reset();
        $this->driver = new FakeMultipartUploadDriver;
    }

    protected function tearDown(): void
    {
        FakeMultipartUploadDriver::reset();

        parent::tearDown();
    }

    private function uploadSession(string $uploadId, string $path = 'docs/a.bin'): UploadSession
    {
        return UploadSession::factory()->make(['disk' => 'local', 'path' => $path, 'upload_id' => $uploadId]);
    }

    public function test_an_unknown_upload_is_not_found_by_every_operation(): void
    {
        $unknown = $this->uploadSession('no-such-upload');

        foreach ([
            fn () => $this->driver->presignPartUpload($unknown, 1),
            fn () => $this->driver->listParts($unknown),
            fn () => $this->driver->complete($unknown, []),
        ] as $operation) {
            try {
                $operation();
                $this->fail('An unknown upload must not be found.');
            } catch (MultipartUploadNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_upload_id_used_with_another_path_is_not_found(): void
    {
        $created = $this->driver->create('local', 'docs/a.bin', 'a.bin');

        $this->expectException(MultipartUploadNotFoundException::class);
        $this->driver->listParts($this->uploadSession($created['upload_id'], 'docs/other.bin'));
    }

    public function test_small_non_last_parts_are_refused_when_unequal_parts_are_simulated(): void
    {
        FakeMultipartUploadDriver::$unequalParts = true;
        $created = $this->driver->create('local', 'docs/a.bin', 'a.bin');
        $session = $this->uploadSession($created['upload_id']);
        $etag1 = FakeMultipartUploadDriver::uploadPart($created['upload_id'], 1, str_repeat('a', 10));
        $etag2 = FakeMultipartUploadDriver::uploadPart($created['upload_id'], 2, 'tail');

        $this->expectException(MultipartPartTooSmallException::class);
        $this->driver->complete($session, [['PartNumber' => 2, 'ETag' => $etag2], ['PartNumber' => 1, 'ETag' => $etag1]]);
    }

    public function test_parts_are_joined_in_order_and_the_upload_is_forgotten(): void
    {
        $created = $this->driver->create('local', 'docs/a.bin', 'a.bin');
        $session = $this->uploadSession($created['upload_id']);
        $etag1 = FakeMultipartUploadDriver::uploadPart($created['upload_id'], 1, 'hello ');
        $etag2 = FakeMultipartUploadDriver::uploadPart($created['upload_id'], 2, 'world');

        $this->driver->complete($session, [['PartNumber' => 2, 'ETag' => $etag2], ['PartNumber' => 1, 'ETag' => $etag1]]);

        $this->assertSame('hello world', Storage::disk('local')->get('docs/a.bin'));
        $this->assertArrayNotHasKey($created['upload_id'], FakeMultipartUploadDriver::$sessions);
    }
}
