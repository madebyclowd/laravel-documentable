<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Contracts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\TenantScopedDedupScope;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class PluggableContractsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'uploads',
        ], $overrides));
    }

    public function test_content_disposition_filename_is_stripped_of_control_chars_and_quotes(): void
    {
        $dirty = "evil\"; x=1\r\nSet-Cookie: pwn=1";

        $clean = DocumentService::sanitizeHeaderFilename($dirty);

        $this->assertStringNotContainsString('"', $clean);
        $this->assertStringNotContainsString("\r", $clean);
        $this->assertStringNotContainsString("\n", $clean);
    }

    public function test_soft_deleted_document_type_is_rejected_by_upload(): void
    {
        $type = $this->makeType();
        $type->delete();
        $owner = TestModel::create(['name' => 'owner']);

        $service = $this->app->make(DocumentService::class);

        $this->expectException(ValidationException::class);
        $service->upload(UploadedFile::fake()->createWithContent('a.txt', 'x'), $type, $owner);
    }

    public function test_soft_deleted_document_type_is_rejected_when_staging_an_upload(): void
    {
        $type = $this->makeType();
        $type->delete();

        $service = $this->app->make(DocumentService::class);

        $this->expectException(ValidationException::class);
        $service->stageUpload(UploadedFile::fake()->createWithContent('a.txt', 'x'), $type);
    }

    public function test_the_default_authorizer_is_gate_backed_and_denies_what_the_app_has_not_defined(): void
    {
        config()->set('documentable.authorization.resolver', null);

        $authorizer = $this->app->make(AuthorizesDocumentAccess::class);
        $type = $this->makeType();

        $this->assertInstanceOf(GateDocumentAuthorizer::class, $authorizer);
        $this->assertFalse($authorizer->canInitiateUpload(null, $type));
        $this->assertFalse($authorizer->canDelete(null, new Document));
    }

    public function test_custom_authorizer_binding_overrides_default(): void
    {
        config()->set('documentable.authorization.resolver', FakeAuthorizer::class);

        $authorizer = $this->app->make(AuthorizesDocumentAccess::class);

        $this->assertInstanceOf(FakeAuthorizer::class, $authorizer);
        $this->assertFalse($authorizer->canInitiateUpload(null, $this->makeType()));
    }

    public function test_the_package_ships_no_allow_everything_authorizer(): void
    {
        $this->assertFalse(class_exists('MadeByClowd\\Documentable\\Defaults\\PermissiveDocumentAuthorizer'));
    }

    public function test_default_dedup_scope_shares_storage_across_different_owners(): void
    {
        $type = $this->makeType();
        $ownerA = TestModel::create(['name' => 'a']);
        $ownerB = TestModel::create(['name' => 'b']);

        $service = $this->app->make(DocumentService::class);

        $docA = $service->upload(UploadedFile::fake()->createWithContent('a.txt', 'same bytes'), $type, $ownerA);
        $docB = $service->upload(UploadedFile::fake()->createWithContent('b.txt', 'same bytes'), $type, $ownerB);

        $this->assertSame($docA->storage_file_id, $docB->storage_file_id);
    }

    public function test_tenant_scoped_dedup_resolver_separates_storage_per_tenant(): void
    {
        config()->set('documentable.dedup.scope_resolver', TenantScopedDedupScope::class);

        $type = $this->makeType();
        $ownerA = TestModel::create(['name' => 'a']);
        $ownerB = TestModel::create(['name' => 'b']);

        $service = $this->app->make(DocumentService::class);

        $docA = $service->upload(UploadedFile::fake()->createWithContent('a.txt', 'same bytes'), $type, $ownerA);
        $docB = $service->upload(UploadedFile::fake()->createWithContent('b.txt', 'same bytes'), $type, $ownerB);

        $this->assertNotSame($docA->storage_file_id, $docB->storage_file_id);
    }
}
