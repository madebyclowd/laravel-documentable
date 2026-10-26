<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\UploadSessions;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use MadeByClowd\Documentable\Enums\UploadKind;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Tests\TestCase;

class UploadSessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function type(): DocumentType
    {
        return DocumentType::create(['code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 's3', 'path_prefix' => 'docs']);
    }

    public function test_the_table_has_every_column_of_the_design(): void
    {
        $this->assertTrue(Schema::hasTable('upload_sessions'));

        foreach ([
            'id', 'user_id', 'document_type_id', 'disk', 'kind', 'client_filename', 'path', 'upload_id', 'declared_size', 'declared_mime',
            'declared_hash', 'object_size', 'object_mime', 'object_hash', 'status', 'owner_type', 'owner_id', 'storage_file_id', 'expires_at', 'created_at', 'updated_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('upload_sessions', $column), "missing column {$column}");
        }
    }

    public function test_only_the_reaper_and_owner_lookups_are_indexed(): void
    {
        $indexed = collect(Schema::getIndexes('upload_sessions'))->pluck('columns')->map(fn ($c) => implode(',', $c))->all();

        $this->assertContains('user_id,status', $indexed);
        $this->assertContains('status,expires_at', $indexed);

        foreach ($indexed as $columns) {
            $this->assertStringNotContainsString('path', $columns);
            $this->assertStringNotContainsString('upload_id', $columns);
        }
    }

    public function test_long_provider_ids_and_keys_round_trip(): void
    {
        $session = UploadSession::create([
            'user_id' => '7',
            'document_type_id' => $this->type()->id,
            'disk' => 's3',
            'kind' => UploadKind::Multipart,
            'path' => 'docs/'.str_repeat('p', 995),
            'client_filename' => 'a.txt',
            'upload_id' => str_repeat('u', 512),
            'status' => UploadSessionStatus::Initiated,
            'expires_at' => now()->addDay(),
        ]);

        $fresh = UploadSession::find($session->id);

        $this->assertSame(1000, strlen($fresh->path));
        $this->assertSame(512, strlen($fresh->upload_id));
    }

    public function test_the_id_is_a_ulid_and_the_enums_are_cast(): void
    {
        $session = UploadSession::create([
            'document_type_id' => $this->type()->id,
            'disk' => 's3',
            'kind' => UploadKind::Single,
            'path' => 'docs/a',
            'client_filename' => 'a.txt',
            'status' => UploadSessionStatus::Initiated,
            'expires_at' => now()->addHour(),
        ]);

        $this->assertMatchesRegularExpression('/^[0-9a-hjkmnp-tv-z]{26}$/', $session->id);
        $this->assertSame(UploadKind::Single, $session->fresh()->kind);
        $this->assertSame(UploadSessionStatus::Initiated, $session->fresh()->status);
        $this->assertNull($session->fresh()->user_id);
        $this->assertNull($session->fresh()->upload_id);
        $this->assertSame($session->document_type_id, $session->documentType->id);
    }

    public function test_the_legacy_multipart_table_is_gone(): void
    {
        $this->assertFalse(Schema::hasTable('multipart_uploads'));
    }
}
