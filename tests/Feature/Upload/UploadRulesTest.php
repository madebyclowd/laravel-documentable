<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\Validators\DwgValidator;
use MadeByClowd\Documentable\Validators\DxfValidator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a document type lets in beyond a size and a MIME list: the file's extension, and its first bytes. An
 * extension alone is a claim and a MIME list alone is whatever fileinfo guesses, so a type can also trust the
 * extension for generic MIME types and name validators that read the real signature.
 */
class UploadRulesTest extends TestCase
{
    use RefreshDatabase;

    private const DWG = "AC1032\0\0\0\0\0\0\0\0\0\0\0\0drawing body";

    protected DocumentService $service;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('documentable.validators', ['CAD' => [DwgValidator::class]]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        Storage::fake('test_disk');
        FakeMultipartUploadDriver::reset();

        $this->service = $this->app->make(DocumentService::class);
    }

    private function type(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'CAD', 'name' => 'Drawing', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'cad',
        ], $overrides));
    }

    private function multipart(DocumentType $type, string $filename, string $body): void
    {
        $session = $this->service->initiateMultipartUpload($type, $filename, '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, $body);
        $this->service->completeMultipartUpload($session);
    }

    private function assertRejected(callable $upload, string $message): void
    {
        try {
            $upload();
            $this->fail("Expected a rejection mentioning: {$message}");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, implode(' ', $e->errors()['file'] ?? []));
        }
    }

    // Extensions

    public function test_the_columns_exist_with_safe_defaults(): void
    {
        $this->assertTrue(Schema::hasColumns('document_types', ['allowed_extensions', 'trust_extension_for_generic_mime']));

        $type = $this->type()->refresh();

        $this->assertNull($type->allowed_extensions);
        $this->assertFalse($type->trust_extension_for_generic_mime);
    }

    #[DataProvider('extensions')]
    public function test_the_extension_must_be_on_the_types_list(string $filename, bool $accepted): void
    {
        $type = $this->type(['allowed_extensions' => ['dwg', 'dxf']]);

        $upload = fn () => $this->multipart($type, $filename, self::DWG);

        $accepted ? $upload() : $this->assertRejected($upload, 'extension');
        $this->assertTrue(true);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function extensions(): array
    {
        return [
            'listed' => ['plan.dwg', true],
            'listed in capitals' => ['PLAN.DWG', true],
            'another listed one' => ['plan.dxf', true],
            'not listed' => ['plan.exe', false],
            'no extension' => ['plan', false],
            'a double extension ends in the real one' => ['plan.dwg.exe', false],
            'a dot-only name' => ['.dwg', false],
        ];
    }

    public function test_no_list_means_no_extension_check(): void
    {
        $this->multipart($this->type(), 'plan.exe', self::DWG);

        $this->assertTrue(true);
    }

    // Generic MIME types

    public function test_a_generic_mime_type_is_refused_by_default_even_for_a_listed_extension(): void
    {
        $type = $this->type(['allowed_mimes' => ['image/vnd.dwg'], 'allowed_extensions' => ['dxf']]);

        $this->assertRejected(fn () => $this->multipart($type, 'plan.dxf', "0\nSECTION\n2\nHEADER\n"), 'text/plain');
    }

    public function test_the_opt_in_trusts_a_listed_extension_when_fileinfo_only_says_text_or_octet_stream(): void
    {
        $type = $this->type(['code' => 'PLAN', 'allowed_mimes' => ['image/vnd.dwg'], 'allowed_extensions' => ['dxf'], 'trust_extension_for_generic_mime' => true]);

        $this->multipart($type, 'plan.dxf', "0\nSECTION\n2\nHEADER\n");

        $this->assertTrue(true);
    }

    public function test_the_opt_in_never_applies_to_a_specific_mime_type_that_is_not_allowed(): void
    {
        $type = $this->type(['allowed_mimes' => ['image/vnd.dwg'], 'allowed_extensions' => ['pdf'], 'trust_extension_for_generic_mime' => true]);

        $this->assertRejected(fn () => $this->multipart($type, 'plan.pdf', "%PDF-1.4\nbody"), 'application/pdf');
    }

    public function test_the_opt_in_without_an_extension_list_trusts_nothing(): void
    {
        $type = $this->type(['allowed_mimes' => ['image/vnd.dwg'], 'trust_extension_for_generic_mime' => true]);

        $this->assertRejected(fn () => $this->multipart($type, 'plan.dxf', "0\nSECTION\n"), 'text/plain');
    }

    // Validators

    public function test_a_validator_registered_for_the_type_checks_the_first_bytes(): void
    {
        $type = $this->type();

        $this->assertRejected(fn () => $this->multipart($type, 'plan.dwg', 'this is not a drawing at all'), 'DWG');
    }

    public function test_a_validator_accepts_a_real_file(): void
    {
        $this->multipart($this->type(), 'plan.dwg', self::DWG);

        $this->assertTrue(true);
    }

    public function test_a_validator_applies_to_large_uploads_before_they_are_queued(): void
    {
        config()->set('documentable.verification.sync_hash_max_bytes', 4);
        $this->app->forgetInstance(DocumentableConfig::class);
        $service = $this->app->make(DocumentService::class);
        $type = $this->type();
        $session = $service->initiateMultipartUpload($type, 'plan.dwg', '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'this is not a drawing at all');

        $this->assertRejected(fn () => $service->completeMultipartUpload($session), 'DWG');
    }

    public function test_a_validator_applies_to_a_server_side_upload(): void
    {
        $type = $this->type();
        $owner = TestModel::create(['name' => 'owner']);

        $this->assertRejected(fn () => $this->service->upload(UploadedFile::fake()->createWithContent('plan.dwg', 'not a drawing'), $type, $owner), 'DWG');
    }

    public function test_the_extension_rule_applies_to_a_server_side_upload(): void
    {
        $type = $this->type(['allowed_extensions' => ['dwg']]);
        $owner = TestModel::create(['name' => 'owner']);

        $this->assertRejected(fn () => $this->service->upload(UploadedFile::fake()->createWithContent('plan.exe', self::DWG), $type, $owner), 'extension');
    }

    public function test_a_type_without_validators_is_unaffected(): void
    {
        $type = $this->type(['code' => 'OTHER']);

        $this->multipart($type, 'anything.bin', 'any bytes');

        $this->assertTrue(true);
    }

    public function test_validators_registered_for_another_type_do_not_apply(): void
    {
        config()->set('documentable.validators', ['OTHER' => [DxfValidator::class]]);
        $this->app->forgetInstance(DocumentableConfig::class);
        $service = $this->app->make(DocumentService::class);
        $type = $this->type();
        $session = $service->initiateMultipartUpload($type, 'plan.dwg', '42');
        FakeMultipartUploadDriver::uploadPart($session->upload_id, 1, 'any bytes');

        $service->completeMultipartUpload($session);

        $this->assertTrue(true);
    }
}
