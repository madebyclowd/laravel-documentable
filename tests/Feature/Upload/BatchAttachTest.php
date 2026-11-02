<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Upload;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * A form with many required documents uploads each one early and attaches them all when the user submits, inside
 * one transaction of the app's own. The batch must be all or nothing, including when two slots hold the same bytes.
 */
class BatchAttachTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected TestModel $owner;

    /** @var list<DocumentType> */
    protected array $types = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('test_disk');
        $this->service = $this->app->make(DocumentService::class);
        $this->owner = TestModel::create(['name' => 'Application']);

        foreach (['KTP', 'NPWP', 'SITE_PLAN'] as $code) {
            $this->types[] = DocumentType::create([
                'code' => $code,
                'name' => $code,
                'max_size_mb' => 10,
                'disk' => 'test_disk',
                'path_prefix' => strtolower($code),
                'requires_versioning' => true,
            ]);
        }
    }

    private function stage(DocumentType $type, string $content): UploadSession
    {
        return $this->service->stageUpload(UploadedFile::fake()->createWithContent($type->code.'.txt', $content), $type, '42');
    }

    public function test_many_staged_uploads_attach_to_one_owner_in_one_transaction(): void
    {
        $sessions = array_map(fn (DocumentType $type) => $this->stage($type, 'content of '.$type->code), $this->types);

        $documents = DB::transaction(fn () => array_map(fn ($session) => $this->service->attach($session, $this->owner), $sessions));

        $this->assertCount(3, $documents);
        $this->assertSame(3, Document::where('status', DocumentStatus::Committed)->count());
        $this->assertSame(3, Document::where('is_latest', true)->count());
    }

    public function test_one_bad_session_rolls_the_whole_batch_back(): void
    {
        $good = array_map(fn (DocumentType $type) => $this->stage($type, 'content of '.$type->code), array_slice($this->types, 0, 2));
        $alreadyAttached = $this->stage($this->types[2], 'already attached');
        $this->service->attach($alreadyAttached, TestModel::create(['name' => 'Someone else']));

        try {
            DB::transaction(function () use ($good, $alreadyAttached) {
                foreach ([...$good, $alreadyAttached] as $session) {
                    $this->service->attach($session->fresh(), $this->owner);
                }
            });
            $this->fail('The batch should have been refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, Document::where('documentable_id', $this->owner->getKey())->count(), 'No document of the batch survives.');

        foreach ($good as $session) {
            $this->assertSame(UploadSessionStatus::Uploaded, $session->fresh()->status, 'The sessions stay attachable for a retry.');
        }
    }

    public function test_the_same_bytes_in_two_slots_attach_in_one_transaction(): void
    {
        $first = $this->stage($this->types[0], 'identical bytes');
        $second = $this->stage($this->types[1], 'identical bytes');

        $documents = DB::transaction(fn () => [
            $this->service->attach($first, $this->owner),
            $this->service->attach($second, $this->owner),
        ]);

        $this->assertCount(2, $documents);
        $this->assertSame(2, Document::count());

        foreach (StorageFile::all() as $file) {
            Storage::disk('test_disk')->assertExists($file->path);
        }
    }

    public function test_a_rolled_back_batch_can_be_retried_when_a_session_matched_a_file_already_stored(): void
    {
        $earlier = $this->service->upload(UploadedFile::fake()->createWithContent('KTP.txt', 'same bytes'), $this->types[0], TestModel::create(['name' => 'Earlier owner']));
        $session = $this->stage($this->types[0], 'same bytes');

        try {
            DB::transaction(function () use ($session) {
                $this->service->attach($session->fresh(), $this->owner);

                throw new \RuntimeException('a later step of the batch failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(UploadSessionStatus::Uploaded, $session->fresh()->status);

        $document = $this->service->attach($session->fresh(), $this->owner);

        $this->assertSame(DocumentStatus::Committed, $document->status);
        Storage::disk('test_disk')->assertExists($document->storageFile->path);
        $this->assertSame($earlier->storage_file_id, $document->storage_file_id, 'The retry reuses the stored file.');
    }
}
