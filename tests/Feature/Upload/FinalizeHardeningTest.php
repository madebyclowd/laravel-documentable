<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\DocumentType;
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
