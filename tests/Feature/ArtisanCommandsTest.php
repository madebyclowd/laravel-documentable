<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class ArtisanCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_types_creates_document_types_from_config(): void
    {
        config()->set('documentable.types', [
            'invoice' => [
                'name' => 'Invoice',
                'max_size_mb' => 10,
                'allowed_mimes' => ['application/pdf'],
                'disk' => 'local',
                'path_prefix' => 'invoices',
            ],
        ]);

        $this->artisan('documents:sync-types')->assertExitCode(0);

        $type = DocumentType::where('code', 'invoice')->first();
        $this->assertNotNull($type);
        $this->assertSame('Invoice', $type->name);
        $this->assertSame(10, $type->max_size_mb);
    }

    public function test_sync_types_updates_existing_type_on_rerun(): void
    {
        config()->set('documentable.types', [
            'invoice' => ['name' => 'Invoice', 'max_size_mb' => 10, 'disk' => 'local', 'path_prefix' => 'invoices'],
        ]);
        $this->artisan('documents:sync-types')->assertExitCode(0);

        config()->set('documentable.types.invoice.max_size_mb', 20);
        $this->artisan('documents:sync-types')->assertExitCode(0);

        $this->assertSame(1, DocumentType::where('code', 'invoice')->count());
        $this->assertSame(20, DocumentType::where('code', 'invoice')->first()->max_size_mb);
    }

    public function test_sync_types_prune_deactivates_missing_types(): void
    {
        DocumentType::create([
            'code' => 'stale', 'name' => 'Stale', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);

        config()->set('documentable.types', []);

        $this->artisan('documents:sync-types --prune')->assertExitCode(0);

        $this->assertTrue(DocumentType::withTrashed()->where('code', 'stale')->first()->trashed());
    }

    public function test_sync_types_revives_previously_deactivated_type(): void
    {
        $type = DocumentType::create([
            'code' => 'invoice', 'name' => 'Invoice', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);
        $type->delete();

        config()->set('documentable.types', [
            'invoice' => ['name' => 'Invoice', 'max_size_mb' => 15, 'disk' => 'local', 'path_prefix' => 'invoices'],
        ]);

        $this->artisan('documents:sync-types')->assertExitCode(0);

        $revived = DocumentType::where('code', 'invoice')->first();
        $this->assertNotNull($revived);
        $this->assertFalse($revived->trashed());
        $this->assertSame(15, $revived->max_size_mb);
    }

    public function test_list_command_reports_document_type_counts(): void
    {
        $type = DocumentType::create([
            'code' => 'invoice', 'name' => 'Invoice', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);
        $owner = TestModel::create(['name' => 'owner']);
        Document::create([
            'storage_file_id' => StorageFile::create([
                'file_hash' => 'abc', 'disk' => 'local', 'path' => 'x/1', 'mime_type' => 'text/plain', 'size_bytes' => 1,
            ])->id,
            'document_type_id' => $type->id,
            'document_group_id' => (string) Str::uuid(),
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
            'client_filename' => 'a.txt',
            'version' => 1,
            'is_latest' => true,
            'latest_marker' => (string) Str::uuid(),
        ]);

        $this->artisan('documents:list')
            ->expectsOutputToContain('invoice')
            ->assertExitCode(0);
    }

    public function test_verify_command_detects_and_repairs_drift(): void
    {
        $type = DocumentType::create([
            'code' => 'invoice', 'name' => 'Invoice', 'max_size_mb' => 5, 'disk' => 'local', 'path_prefix' => 'x',
        ]);
        $owner = TestModel::create(['name' => 'owner']);
        $storageFile = StorageFile::create([
            'file_hash' => 'abc', 'disk' => 'local', 'path' => 'x/1', 'mime_type' => 'text/plain', 'size_bytes' => 1,
        ]);

        // Drifted on purpose: is_latest = true but latest_marker = null, the exact
        // inconsistency the reaper/service layer should never produce.
        $document = Document::create([
            'storage_file_id' => $storageFile->id,
            'document_type_id' => $type->id,
            'document_group_id' => (string) Str::uuid(),
            'documentable_type' => $owner->getMorphClass(),
            'documentable_id' => $owner->getKey(),
            'client_filename' => 'a.txt',
            'version' => 1,
            'is_latest' => true,
            'latest_marker' => null,
        ]);

        $this->artisan('documents:verify')
            ->expectsOutputToContain('1 document(s)')
            ->assertExitCode(0);

        $this->assertTrue($document->fresh()->is_latest);

        $this->artisan('documents:verify --repair')->assertExitCode(0);

        $this->assertFalse($document->fresh()->is_latest);
    }

    public function test_verify_command_reports_clean_when_no_drift(): void
    {
        $this->artisan('documents:verify')
            ->expectsOutputToContain('No latest_marker')
            ->assertExitCode(0);
    }

    public function test_install_command_runs_end_to_end_without_error(): void
    {
        $this->artisan('documents:install')
            ->expectsConfirmation('Run migrations now?', 'no')
            ->expectsChoice(
                "Multipart ETag strategy?\n".
                "  client: fewer round trips, but requires bucket CORS ExposeHeaders: [\"ETag\"].\n".
                '  server-authoritative: no CORS dependency, costs one extra ListParts call per completion.',
                'server-authoritative',
                ['server-authoritative', 'client']
            )
            ->expectsChoice(
                "Document type catalog?\n".
                "  code-first: define types in config('documentable.types'), synced via documents:sync-types (git-versioned, PR-reviewed, cacheable).\n".
                '  db-only: manage the document_types table directly through your own admin layer (runtime-editable, no deploy needed).',
                'code-first',
                ['code-first', 'db-only']
            )
            ->expectsConfirmation(
                'Generate a starter AuthorizesDocumentAccess implementation? (default is permissive — allows everything)',
                'no'
            )
            ->assertExitCode(0);
    }
}
