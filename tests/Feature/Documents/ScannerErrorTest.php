<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\ScanResult;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A scanner that could not reach a verdict (`ScanResult::Error`: the ClamAV sidecar is down, the scan timed
 * out) used to be treated like a clean result, so an outage of the scanner switched scanning off without anyone
 * noticing. An unscanned file is now rejected by default, and `security.scan_error_policy = allow` is the
 * explicit opt-out.
 */
class ScannerErrorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        $this->app->bind(ScansUploadedFile::class, fn () => new class implements ScansUploadedFile
        {
            public function scan(string $disk, string $path): ScanResult
            {
                return ScanResult::Error;
            }
        });
    }

    protected function upload(): void
    {
        $type = DocumentType::create(['code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'docs']);

        $this->app->make(DocumentService::class)->upload(
            UploadedFile::fake()->createWithContent('a.txt', 'unscanned bytes'),
            $type,
            TestModel::create(['name' => 'owner'])
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function rejectingPolicies(): array
    {
        return [
            'default' => [null],
            'reject' => ['reject'],
            'a value nobody defined' => ['yolo'],
        ];
    }

    #[DataProvider('rejectingPolicies')]
    public function test_an_unscanned_file_is_rejected_and_leaves_no_object(mixed $policy): void
    {
        if ($policy !== null) {
            config()->set('documentable.security.scan_error_policy', $policy);
        }

        try {
            $this->upload();
            $this->fail('A file the scanner could not judge must not be stored.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
        }

        $this->assertSame(0, StorageFile::count());
        $this->assertSame([], Storage::disk('test_disk')->allFiles());
    }

    public function test_the_allow_policy_stores_the_file_and_logs_that_it_was_not_scanned(): void
    {
        config()->set('documentable.security.scan_error_policy', 'allow');
        Log::shouldReceive('warning')->once();

        $this->upload();

        $this->assertSame(1, StorageFile::count());
    }
}
