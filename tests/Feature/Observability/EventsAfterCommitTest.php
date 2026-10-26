<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Observability;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentPurged;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Document events used to fire inside the service's own transaction. A listener (or a queued job) could then
 * act on a document that a surrounding transaction later rolled back, or run before the row was visible to
 * another connection. The events now wait for the outermost commit.
 *
 * Does not use RefreshDatabase on purpose: it wraps every test in a transaction, which is exactly the situation
 * under test. It migrates for itself and removes its committed rows in tearDown, so tests that do use
 * RefreshDatabase and run later in the same process still find empty tables.
 */
class EventsAfterCommitTest extends TestCase
{
    protected DocumentService $service;

    protected DocumentType $type;

    /** @var list<string> */
    protected array $seen = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh')->run();
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        $this->service = $this->app->make(DocumentService::class);
        $this->type = DocumentType::create([
            'code' => 'DOC', 'name' => 'Doc', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'docs',
            'allows_multiple' => true, 'requires_versioning' => false,
        ]);

        foreach ([DocumentUploaded::class, DocumentDeleted::class, DocumentPurged::class] as $event) {
            Event::listen($event, function ($payload) use ($event) {
                $this->seen[] = class_basename($event).'@level'.DB::transactionLevel();
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');
        Document::withTrashed()->forceDelete();
        UploadSession::query()->delete();
        StorageFile::query()->delete();
        TestModel::query()->delete();
        DocumentType::withTrashed()->forceDelete();

        parent::tearDown();
    }

    protected function upload(string $name = 'a.txt'): Document
    {
        return $this->service->upload(
            UploadedFile::fake()->createWithContent($name, $name.uniqid('', true)),
            $this->type,
            TestModel::create(['name' => 'owner'])
        );
    }

    public function test_outside_a_transaction_events_dispatch_immediately(): void
    {
        $document = $this->upload();
        $this->service->delete($document);
        $this->service->purge($document);

        $this->assertSame(['DocumentUploaded@level0', 'DocumentDeleted@level0', 'DocumentPurged@level0'], $this->seen);
    }

    public function test_events_from_a_rolled_back_outer_transaction_never_fire(): void
    {
        DB::beginTransaction();
        $document = $this->upload();
        $this->service->delete($document);
        $this->service->purge($document);
        $this->assertSame([], $this->seen, 'Nothing may fire before the outer transaction ends.');
        DB::rollBack();

        $this->assertSame([], $this->seen);
        $this->assertSame(0, Document::withTrashed()->count());
    }

    public function test_events_from_a_committed_outer_transaction_fire_after_the_commit(): void
    {
        DB::beginTransaction();
        $document = $this->upload();
        $this->assertSame([], $this->seen);
        DB::commit();

        $this->assertSame(['DocumentUploaded@level0'], $this->seen);
        $this->assertNotNull(Document::find($document->id));
    }

    public function test_a_queued_listener_is_only_pushed_once_the_row_is_committed(): void
    {
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        QueuedDocumentListener::$seen = [];
        Event::listen(DocumentUploaded::class, QueuedDocumentListener::class);

        DB::beginTransaction();
        $document = $this->upload('queued.txt');
        $this->assertSame(0, DB::table('jobs')->count(), 'The job must not be queued while the transaction is open.');
        DB::commit();

        $this->assertSame(1, DB::table('jobs')->count());

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

        $this->assertSame([$document->id], QueuedDocumentListener::$seen);
    }
}

class QueuedDocumentListener implements ShouldQueue
{
    /** @var list<string> */
    public static array $seen = [];

    public function handle(DocumentUploaded $event): void
    {
        static::$seen[] = Document::findOrFail($event->document->id)->id;
    }
}
