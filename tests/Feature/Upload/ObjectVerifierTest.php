<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Services\ObjectVerifier;
use MadeByClowd\Documentable\Tests\TestCase;

class ObjectVerifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
    }

    private function verifier(): ObjectVerifier
    {
        return $this->app->make(ObjectVerifier::class);
    }

    public function test_it_hashes_sizes_and_sniffs_the_mime_of_a_stored_object(): void
    {
        $body = "%PDF-1.4\n".str_repeat('x', 100_000);
        Storage::disk('test_disk')->put('a.pdf', $body);

        $facts = $this->verifier()->hashAndDetectMime('test_disk', 'a.pdf');

        $this->assertSame(hash('sha256', $body), $facts['hash']);
        $this->assertSame(strlen($body), $facts['size']);
        $this->assertSame('application/pdf', $facts['mime']);
    }

    public function test_an_empty_object_hashes_and_defaults_to_octet_stream(): void
    {
        Storage::disk('test_disk')->put('empty.bin', '');

        $facts = $this->verifier()->hashAndDetectMime('test_disk', 'empty.bin');

        $this->assertSame(hash('sha256', ''), $facts['hash']);
        $this->assertSame(0, $facts['size']);
        $this->assertSame('application/x-empty', $facts['mime']);
    }

    public function test_hashing_a_large_object_stays_in_constant_memory(): void
    {
        $path = Storage::disk('test_disk')->path('big.bin');
        @mkdir(dirname($path), 0777, true);
        $handle = fopen($path, 'wb');
        $block = str_repeat('a', 1024 * 1024);
        for ($i = 0; $i < 64; $i++) {
            fwrite($handle, $block);
        }
        fclose($handle);

        $before = memory_get_peak_usage();
        $facts = $this->verifier()->hashAndDetectMime('test_disk', 'big.bin');

        $this->assertSame(64 * 1024 * 1024, $facts['size']);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before, 'Hashing 64 MiB must not buffer it.');
    }

    public function test_the_head_read_returns_at_most_the_requested_bytes_without_reading_the_rest(): void
    {
        Storage::disk('test_disk')->put('a.bin', str_repeat('z', 100_000));

        $head = $this->verifier()->head('test_disk', 'a.bin', 8192);

        $this->assertSame(8192, strlen($head));
    }

    public function test_the_head_of_a_short_object_is_the_whole_object(): void
    {
        Storage::disk('test_disk')->put('short.bin', 'abc');

        $this->assertSame('abc', $this->verifier()->head('test_disk', 'short.bin', 8192));
    }

    public function test_the_size_comes_from_the_disk_without_reading_the_body(): void
    {
        Storage::disk('test_disk')->put('a.bin', str_repeat('z', 4321));

        $this->assertSame(4321, $this->verifier()->size('test_disk', 'a.bin'));
    }

    public function test_the_driver_contract_no_longer_offers_a_provider_checksum(): void
    {
        $this->assertFalse(method_exists(MultipartUploadDriver::class, 'retrieveChecksum'));
        $this->assertFalse(method_exists(ObjectVerifier::class, 'assembledObjectInfo'));
    }
}
