<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\StampingMetadataResolver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;

/**
 * The attach metadata resolver lets the server stamp keys the client must not be trusted with. It runs for every
 * transport because they all end in `attach()`.
 */
class AttachMetadataResolverTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentType $type;

    protected TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test_disk');
        StampingMetadataResolver::reset();

        $this->type = DocumentType::create([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'allows_multiple' => true,
        ]);
        $this->owner = TestModel::create(['name' => 'Owner']);
    }

    private function useResolver(?string $class = StampingMetadataResolver::class): DocumentService
    {
        config()->set('documentable.attach.metadata_resolver', $class);
        $this->app->forgetInstance(DocumentableConfig::class);

        return $this->app->make(DocumentService::class);
    }

    private function file(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('a.txt', 'hello');
    }

    public function test_the_resolver_stamps_the_document_on_a_direct_upload(): void
    {
        StampingMetadataResolver::$stamp = ['pending_transition' => true];

        $document = $this->useResolver()->upload($this->file(), $this->type, $this->owner);

        $this->assertTrue($document->metadata['pending_transition']);
    }

    public function test_the_resolver_stamps_a_staged_upload_attached_later(): void
    {
        StampingMetadataResolver::$stamp = ['pending_transition' => true];
        $service = $this->useResolver();

        $session = $service->stageUpload($this->file(), $this->type, '42');
        $document = $service->attach($session, $this->owner);

        $this->assertTrue($document->metadata['pending_transition']);
    }

    public function test_it_is_asked_about_the_session_the_owner_and_what_the_client_requested(): void
    {
        $service = $this->useResolver();
        $session = $service->stageUpload($this->file(), $this->type, '42');

        $service->attach($session, $this->owner, new AttachOptions(['note' => 'from client']));

        $this->assertSame($session->id, StampingMetadataResolver::$session->id);
        $this->assertTrue($this->owner->is(StampingMetadataResolver::$owner));
        $this->assertSame(['note' => 'from client'], StampingMetadataResolver::$requested->metadata);
    }

    public function test_the_resolver_beats_what_the_client_sent(): void
    {
        StampingMetadataResolver::$stamp = ['pending_transition' => true];

        $document = $this->useResolver()->upload($this->file(), $this->type, $this->owner, ['pending_transition' => false, 'note' => 'kept']);

        $this->assertTrue($document->metadata['pending_transition']);
        $this->assertSame('kept', $document->metadata['note'], 'Client keys the resolver does not touch survive.');
    }

    public function test_what_the_package_measured_beats_both_the_client_and_the_resolver(): void
    {
        StampingMetadataResolver::$stamp = ['size' => 'from resolver'];

        $document = $this->useResolver()->upload($this->file(), $this->type, $this->owner, ['size' => 'from client', 'mime_type' => 'from client']);

        $this->assertSame($document->storageFile->size_bytes, $document->metadata['size']);
        $this->assertSame($document->storageFile->mime_type, $document->metadata['mime_type']);
    }

    public function test_the_client_cannot_invent_an_original_filename(): void
    {
        $document = $this->useResolver(null)->upload($this->file(), $this->type, $this->owner, ['original_filename' => 'forged.exe']);

        $this->assertArrayNotHasKey('original_filename', $document->metadata, 'The name needed no normalizing, so there is no original to record.');
    }

    public function test_the_client_can_no_longer_overwrite_measured_keys_without_a_resolver(): void
    {
        $document = $this->useResolver(null)->upload($this->file(), $this->type, $this->owner, ['size' => 'from client']);

        $this->assertSame($document->storageFile->size_bytes, $document->metadata['size']);
    }

    public function test_a_resolver_that_does_not_implement_the_contract_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attach.metadata_resolver');

        $this->useResolver(\stdClass::class);
        $this->app->make(DocumentableConfig::class);
    }
}
