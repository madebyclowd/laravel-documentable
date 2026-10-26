<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;

/**
 * The two data migrations that move an existing install onto upload sessions: open `pending` documents become
 * sessions, and `multipart_uploads` is drained and dropped.
 */
class SessionMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        FakeMultipartUploadDriver::reset();

        $this->type = DocumentType::create(['code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'docs']);
    }

    private function migration(string $file): object
    {
        return require __DIR__.'/../../../database/migrations/'.$file;
    }

    private function insertDocument(string $status, ?string $expiresAt, ?string $createdBy = '42'): string
    {
        $storageFile = StorageFile::create(['file_hash' => Str::random(64), 'disk' => 'test_disk', 'path' => 'docs/'.Str::random(8), 'mime_type' => 'text/plain', 'size_bytes' => 7]);
        $id = (string) Str::uuid();

        DB::table('documents')->insert([
            'id' => $id,
            'storage_file_id' => $storageFile->id,
            'document_type_id' => $this->type->id,
            'document_group_id' => (string) Str::uuid(),
            'client_filename' => 'legacy.txt',
            'version' => 1,
            'is_latest' => true,
            'status' => $status,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // Pending documents become sessions

    public function test_an_open_pending_document_becomes_an_uploaded_session_on_the_same_stored_file(): void
    {
        $id = $this->insertDocument('pending', now()->addHour()->toDateTimeString());
        $storageFileId = DB::table('documents')->where('id', $id)->value('storage_file_id');

        $this->migration('2026_10_26_000009_convert_pending_documents_to_upload_sessions.php')->up();

        $session = UploadSession::firstOrFail();
        $this->assertSame(UploadSessionStatus::Uploaded, $session->status);
        $this->assertSame($storageFileId, $session->storage_file_id);
        $this->assertSame('legacy.txt', $session->client_filename);
        $this->assertSame('42', $session->user_id);
        $this->assertSame(0, DB::table('documents')->where('id', $id)->count());
    }

    public function test_an_expired_pending_document_is_removed_without_a_session(): void
    {
        $id = $this->insertDocument('pending', now()->subHour()->toDateTimeString());

        $this->migration('2026_10_26_000009_convert_pending_documents_to_upload_sessions.php')->up();

        $this->assertSame(0, UploadSession::count());
        $this->assertSame(0, DB::table('documents')->where('id', $id)->count());
    }

    public function test_committed_documents_are_left_alone_even_without_an_owner(): void
    {
        $id = $this->insertDocument('committed', null);

        $this->migration('2026_10_26_000009_convert_pending_documents_to_upload_sessions.php')->up();

        $this->assertSame(1, DB::table('documents')->where('id', $id)->count());
        $this->assertSame(0, UploadSession::count());
    }

    // multipart_uploads is drained and dropped

    private function legacyTable(): void
    {
        Schema::dropIfExists('multipart_uploads');
        (require __DIR__.'/../../../database/migrations/2026_07_05_000004_create_multipart_uploads_table.php')->up();
        $this->migration('2026_10_24_000006_widen_multipart_uploads_columns.php')->up();
    }

    private function legacyRow(string $uploadId, \DateTimeInterface $expiresAt): void
    {
        DB::table('multipart_uploads')->insert([
            'id' => (string) Str::uuid(), 'path' => 'docs/'.$uploadId, 'upload_id' => $uploadId, 'user_id' => '42',
            'document_type_id' => $this->type->id, 'expires_at' => $expiresAt, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_table_is_dropped_when_every_session_in_it_has_expired(): void
    {
        $this->legacyTable();
        $this->legacyRow('old-1', now()->subDay());

        $this->migration('2026_10_26_000010_drop_multipart_uploads_table.php')->up();

        $this->assertFalse(Schema::hasTable('multipart_uploads'));
    }

    public function test_the_migration_refuses_to_drop_the_table_while_a_session_is_still_open(): void
    {
        $this->legacyTable();
        $this->legacyRow('live-1', now()->addHour());

        try {
            $this->migration('2026_10_26_000010_drop_multipart_uploads_table.php')->up();
            $this->fail('Expected the migration to refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 multipart upload session(s) are still open', $e->getMessage());
            $this->assertStringContainsString('DOCUMENTABLE_FORCE_DRAIN', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('multipart_uploads'));
        $this->assertSame(1, DB::table('multipart_uploads')->count());
    }

    public function test_forcing_aborts_the_provider_uploads_and_drops_the_table(): void
    {
        config()->set('documentable.upgrade.force_drain', true);
        $this->legacyTable();
        FakeMultipartUploadDriver::$sessions['live-1'] = [1 => ['etag' => '"e"', 'body' => 'x']];
        $this->legacyRow('live-1', now()->addHour());

        $this->migration('2026_10_26_000010_drop_multipart_uploads_table.php')->up();

        $this->assertFalse(Schema::hasTable('multipart_uploads'));
        $this->assertArrayNotHasKey('live-1', FakeMultipartUploadDriver::$sessions);
    }

    public function test_a_provider_that_cannot_abort_is_logged_and_the_drain_still_completes(): void
    {
        config()->set('documentable.upgrade.force_drain', true);
        $this->legacyTable();
        $this->legacyRow('stuck-1', now()->addHour());
        FakeMultipartUploadDriver::$abortErrors['stuck-1'] = new RuntimeException('provider unreachable');
        Log::shouldReceive('warning')->once();

        $this->migration('2026_10_26_000010_drop_multipart_uploads_table.php')->up();

        $this->assertFalse(Schema::hasTable('multipart_uploads'));
    }

    public function test_running_the_drain_when_the_table_is_already_gone_does_nothing(): void
    {
        $this->assertFalse(Schema::hasTable('multipart_uploads'));

        $this->migration('2026_10_26_000010_drop_multipart_uploads_table.php')->up();

        $this->assertFalse(Schema::hasTable('multipart_uploads'));
    }
}
