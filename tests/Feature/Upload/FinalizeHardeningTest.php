<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * A presigned finalize receives only a client-supplied path, so the package cannot tell an object
 * the caller uploaded from any other key in the bucket. A failed finalize must therefore never
 * delete the object it was pointed at.
 */
class FinalizeHardeningTest extends TestCase
{
    use RefreshDatabase;

    private DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        $this->service = $this->app->make(DocumentService::class);
    }

    private function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'FINALIZE_HARDENING',
            'name' => 'Finalize hardening',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'uploads',
        ], $overrides));
    }

    public function test_finalizing_the_same_path_twice_rejects_the_second_call_and_keeps_the_stored_object(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        Storage::disk('test_disk')->put('uploads/once.bin', 'registered content');

        $first = $this->service->finalizeDirectUpload('uploads/once.bin', $type, $owner, 'once.bin');

        try {
            $this->service->finalizeDirectUpload('uploads/once.bin', $type, $owner, 'again.bin');
            $this->fail('A path that already backs a stored file must not be finalized again.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('path', $e->errors());
        }

        $this->assertSame('uploads/once.bin', $first->storageFile->path);
        $this->assertTrue(Storage::disk('test_disk')->exists('uploads/once.bin'));
        $this->assertSame(1, StorageFile::count());
        $this->assertSame(1, Document::count());
    }

    public function test_a_path_outside_the_type_prefix_is_accepted_by_default_so_custom_path_generators_keep_working(): void
    {
        $type = $this->makeType(['path_prefix' => 'invoices']);
        $owner = TestModel::create(['name' => 'owner']);
        Storage::disk('test_disk')->put('tenant-7/2026/sharded.bin', 'sharded layout');

        $document = $this->service->finalizeDirectUpload('tenant-7/2026/sharded.bin', $type, $owner, 'sharded.bin');

        $this->assertSame('tenant-7/2026/sharded.bin', $document->storageFile->path);
    }

    public function test_enforce_path_prefix_rejects_a_path_outside_the_type_prefix_and_keeps_the_object(): void
    {
        config()->set('documentable.security.enforce_path_prefix', true);
        $type = $this->makeType(['path_prefix' => 'invoices']);
        $owner = TestModel::create(['name' => 'owner']);
        Storage::disk('test_disk')->put('payroll/2026/salaries.bin', 'belongs to another type');

        try {
            $this->service->finalizeDirectUpload('payroll/2026/salaries.bin', $type, $owner, 'salaries.bin');
            $this->fail('A path outside the type prefix must be rejected when the prefix is enforced.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('path', $e->errors());
            $this->assertTrue(Storage::disk('test_disk')->exists('payroll/2026/salaries.bin'));
        }
    }

    public function test_enforce_path_prefix_accepts_a_path_inside_the_type_prefix(): void
    {
        config()->set('documentable.security.enforce_path_prefix', true);
        $type = $this->makeType(['path_prefix' => 'invoices']);
        $owner = TestModel::create(['name' => 'owner']);
        Storage::disk('test_disk')->put('invoices/9d1c.bin', 'invoice bytes');

        $document = $this->service->finalizeDirectUpload('invoices/9d1c.bin', $type, $owner, 'invoice.bin');

        $this->assertSame('invoices/9d1c.bin', $document->storageFile->path);
    }

    public function test_enforce_path_prefix_does_not_treat_a_sibling_prefix_as_a_match(): void
    {
        config()->set('documentable.security.enforce_path_prefix', true);
        $type = $this->makeType(['path_prefix' => 'invoices']);
        $owner = TestModel::create(['name' => 'owner']);
        Storage::disk('test_disk')->put('invoices-archive/old.bin', 'sibling directory');

        $this->expectException(ValidationException::class);

        $this->service->finalizeDirectUpload('invoices-archive/old.bin', $type, $owner, 'old.bin');
    }

    public function test_finalize_with_a_wrong_expected_hash_rejects_without_deleting_the_object(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        Storage::disk('test_disk')->put('somebody-else/secret.bin', 'not yours');

        try {
            $this->service->finalizeDirectUpload(
                'somebody-else/secret.bin',
                $type,
                $owner,
                'secret.bin',
                hash('sha256', 'a different content')
            );
            $this->fail('A hash mismatch must be rejected.');
        } catch (ValidationException) {
            $this->assertTrue(Storage::disk('test_disk')->exists('somebody-else/secret.bin'));
        }
    }
}
