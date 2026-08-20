<?php

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentAccessLog;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Repositories\DocumentRepository;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Trivial Eloquent relation/lookup methods that no other feature test happens to
 * touch as a side effect (every other test asserts through JSON/service-layer
 * results, never by calling these directly).
 */
class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeDocument(): Document
    {
        $type = DocumentType::create([
            'code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);
        $owner = TestModel::create(['name' => 'owner']);
        $storageFile = StorageFile::create([
            'file_hash' => 'abc', 'disk' => 'local', 'path' => 'x/1', 'mime_type' => 'text/plain', 'size_bytes' => 1,
        ]);

        return Document::create([
            'storage_file_id' => $storageFile->id,
            'document_type_id' => $type->id,
            'document_group_id' => (string) Str::uuid(),
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
            'client_filename' => 'a.txt',
            'version' => 1,
            'is_latest' => true,
            'latest_marker' => (string) Str::uuid(),
        ]);
    }

    public function test_document_document_type_relation_resolves_the_owning_type(): void
    {
        $document = $this->makeDocument();

        $this->assertInstanceOf(BelongsTo::class, $document->documentType());
        $this->assertSame('DOC', $document->documentType->code);
    }

    public function test_document_documentable_relation_resolves_the_polymorphic_owner(): void
    {
        $document = $this->makeDocument();

        $this->assertInstanceOf(MorphTo::class, $document->documentable());
        $this->assertInstanceOf(TestModel::class, $document->documentable);
    }

    public function test_documentable_trait_exposes_a_morph_many_documents_relation(): void
    {
        $document = $this->makeDocument();
        $owner = $document->documentable;

        $this->assertInstanceOf(MorphMany::class, $owner->documents());
        $this->assertTrue($owner->documents->contains($document));
    }

    public function test_storage_file_has_many_documents(): void
    {
        $document = $this->makeDocument();

        $this->assertInstanceOf(HasMany::class, $document->storageFile->documents());
        $this->assertTrue($document->storageFile->documents->contains($document));
    }

    public function test_document_access_log_belongs_to_document(): void
    {
        $document = $this->makeDocument();
        $log = DocumentAccessLog::create([
            'document_id' => $document->id,
            'actor_id' => 'user-1',
            'action' => 'view',
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
        ]);

        $this->assertInstanceOf(BelongsTo::class, $log->document());
        $this->assertTrue($log->document->is($document));
    }

    public function test_document_repository_find_by_id_returns_the_matching_document(): void
    {
        $document = $this->makeDocument();

        $found = (new DocumentRepository)->findById($document->id);

        $this->assertTrue($found->is($document));
    }
}
