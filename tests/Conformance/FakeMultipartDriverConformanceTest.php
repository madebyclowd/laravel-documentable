<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Conformance;

use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;

class FakeMultipartDriverConformanceTest extends MultipartDriverConformanceTest
{
    private FakeMultipartUploadDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        FakeMultipartUploadDriver::reset();
        FakeMultipartUploadDriver::$unequalParts = true;

        $this->driver = new FakeMultipartUploadDriver;
    }

    protected function driver(): MultipartUploadDriver
    {
        return $this->driver;
    }

    protected function createSession(string $path, string $filename): UploadSession
    {
        $created = $this->driver->create('test_disk', $path, $filename);

        $session = new UploadSession;
        $session->disk = 'test_disk';
        $session->path = $path;
        $session->upload_id = $created['upload_id'];

        return $session;
    }

    protected function uploadPart(UploadSession $session, int $partNumber, string $body): string
    {
        return FakeMultipartUploadDriver::uploadPart((string) $session->upload_id, $partNumber, $body);
    }

    protected function getStoredContents(UploadSession $session): string
    {
        return (string) Storage::disk($session->disk)->get($session->path);
    }
}
