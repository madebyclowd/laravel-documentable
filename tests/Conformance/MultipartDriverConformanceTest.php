<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Conformance;

use Illuminate\Support\Str;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Exceptions\MultipartPartTooSmallException;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Conformance test suite verifying that a MultipartUploadDriver implementation
 * honors the contractual requirements:
 *
 * 1. Re-uploading the same part number is safe and last-write-wins.
 * 2. Aborting twice does not fail or throws MultipartUploadNotFoundException.
 * 3. An unknown upload id yields MultipartUploadNotFoundException from listParts and complete.
 * 4. Completing with a non-last part smaller than 5 MiB yields MultipartPartTooSmallException.
 * 5. A session cannot be used to complete or touch a different storage path.
 */
abstract class MultipartDriverConformanceTest extends TestCase
{
    abstract protected function driver(): MultipartUploadDriver;

    abstract protected function createSession(string $path, string $filename): UploadSession;

    abstract protected function uploadPart(UploadSession $session, int $partNumber, string $body): string;

    abstract protected function getStoredContents(UploadSession $session): string;

    protected function enforcesMinimumPartSize(): bool
    {
        return true;
    }

    public function test_reuploading_the_same_part_number_is_idempotent_and_last_write_wins(): void
    {
        $driver = $this->driver();
        $path = 'conformance/idempotent-'.bin2hex(random_bytes(6)).'.bin';
        $session = $this->createSession($path, basename($path));

        // Use 5 MiB payloads so minimum part size checks pass
        $payload1 = str_repeat('A', 5 * 1024 * 1024);
        $payload2 = str_repeat('B', 5 * 1024 * 1024);

        $this->uploadPart($session, 1, $payload1);
        $etag2 = $this->uploadPart($session, 1, $payload2);

        $driver->complete($session, [
            ['PartNumber' => 1, 'ETag' => $etag2],
        ]);

        $this->assertSame($payload2, $this->getStoredContents($session));
    }

    public function test_abort_twice_does_not_fail_or_throws_not_found(): void
    {
        $driver = $this->driver();
        $path = 'conformance/abort-'.bin2hex(random_bytes(6)).'.bin';
        $session = $this->createSession($path, basename($path));

        $driver->abort($session);

        try {
            $driver->abort($session);
            $this->assertTrue(true);
        } catch (MultipartUploadNotFoundException) {
            $this->assertTrue(true);
        }
    }

    public function test_unknown_upload_id_yields_not_found(): void
    {
        $driver = $this->driver();
        $path = 'conformance/unknown-'.bin2hex(random_bytes(6)).'.bin';
        $session = $this->createSession($path, basename($path));

        $session->upload_id = (string) Str::uuid();

        $this->expectException(MultipartUploadNotFoundException::class);
        $driver->listParts($session);
    }

    public function test_unknown_upload_id_yields_not_found_on_complete(): void
    {
        $driver = $this->driver();
        $path = 'conformance/unknown-complete-'.bin2hex(random_bytes(6)).'.bin';
        $session = $this->createSession($path, basename($path));

        $session->upload_id = (string) Str::uuid();

        $this->expectException(MultipartUploadNotFoundException::class);
        $driver->complete($session, [
            ['PartNumber' => 1, 'ETag' => '"etag-1"'],
        ]);
    }

    public function test_non_last_part_smaller_than_5_mib_yields_part_too_small_exception(): void
    {
        if (! $this->enforcesMinimumPartSize()) {
            $this->markTestSkipped('Driver does not enforce minimum part size.');
        }

        $driver = $this->driver();
        $path = 'conformance/too-small-'.bin2hex(random_bytes(6)).'.bin';
        $session = $this->createSession($path, basename($path));

        $part1Etag = $this->uploadPart($session, 1, 'short');
        $part2Etag = $this->uploadPart($session, 2, 'final');

        try {
            $driver->complete($session, [
                ['PartNumber' => 1, 'ETag' => $part1Etag],
                ['PartNumber' => 2, 'ETag' => $part2Etag],
            ]);
            $this->fail('Expected MultipartPartTooSmallException was not thrown.');
        } catch (MultipartPartTooSmallException $e) {
            $this->assertStringContainsString($path, $e->getMessage());
        } finally {
            try {
                $driver->abort($session);
            } catch (\Throwable) {
                // Ignore cleanup error
            }
        }
    }

    public function test_session_cannot_touch_another_path(): void
    {
        $driver = $this->driver();
        $pathA = 'conformance/path-a-'.bin2hex(random_bytes(6)).'.bin';
        $pathB = 'conformance/path-b-'.bin2hex(random_bytes(6)).'.bin';

        $session = $this->createSession($pathA, basename($pathA));
        $partEtag = $this->uploadPart($session, 1, str_repeat('Z', 5 * 1024 * 1024));

        // Point the session at path B
        $session->path = $pathB;

        try {
            $driver->complete($session, [
                ['PartNumber' => 1, 'ETag' => $partEtag],
            ]);
            $this->fail('Expected completion on mismatched path to fail.');
        } catch (MultipartUploadNotFoundException $e) {
            $this->assertStringContainsString($pathB, $e->getMessage());
        } finally {
            $session->path = $pathA;
            try {
                $driver->abort($session);
            } catch (\Throwable) {
                // Ignore cleanup error
            }
        }
    }
}
