<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Events\DocumentVersionSuperseded;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Attaching an uploaded session to an owner that already holds a document of the same single-slot type must
 * go through the same slot and version resolution as an upload. Before sessions, a detached document moved to
 * such an owner became a second `is_latest` row: the previous one was never demoted, so the owner had two
 * "current" documents and every "latest" lookup picked one arbitrarily.
 */
class AttachVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        FakeMultipartUploadDriver::reset();

        $this->service = $this->app->make(DocumentService::class);
    }

    protected function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'SLOT',
            'name' => 'Single slot',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'uploads',
            'allows_multiple' => false,
            'requires_versioning' => true,
        ], $overrides));
    }

    protected function file(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $name.uniqid('', true));
    }

    protected function latestCount(TestModel $owner, DocumentType $type): int
    {
        return Document::where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', (string) $owner->getKey())
            ->where('document_type_id', $type->id)
            ->where('is_latest', true)
            ->count();
    }

    public function test_attaching_into_an_occupied_single_slot_keeps_exactly_one_latest_and_bumps_the_version(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $existing = $this->service->upload($this->file('first.txt'), $type, $owner);
        $staged = $this->service->stageUpload($this->file('second.txt'), $type, '42');

        Event::fake([DocumentVersionSuperseded::class]);

        $moved = $this->service->attach($staged, $owner);

        $this->assertSame(1, $this->latestCount($owner, $type));
        $this->assertSame(2, $moved->fresh()->version);
        $this->assertTrue($moved->fresh()->is_latest);
        $this->assertSame($existing->document_group_id, $moved->fresh()->document_group_id);
        $this->assertFalse($existing->fresh()->is_latest);
        $this->assertSame('committed', $moved->fresh()->status->value);
        Event::assertDispatched(DocumentVersionSuperseded::class, fn ($event) => $event->previous->is($existing) && $event->new->is($moved));
    }

    public function test_a_hard_replace_type_soft_deletes_the_document_it_replaces(): void
    {
        $type = $this->makeType(['requires_versioning' => false]);
        $owner = TestModel::create(['name' => 'owner']);
        $existing = $this->service->upload($this->file('first.txt'), $type, $owner);
        $staged = $this->service->stageUpload($this->file('second.txt'), $type, '42');

        $moved = $this->service->attach($staged, $owner);

        $this->assertSoftDeleted($existing);
        $this->assertSame(1, $this->latestCount($owner, $type));
        $this->assertSame(1, $moved->fresh()->version);
    }

    public function test_attaching_into_an_empty_slot_starts_a_new_group_at_version_one(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $document = $this->service->attach($this->service->stageUpload($this->file('only.txt'), $type, '42'), $owner);

        $this->assertSame(1, $this->latestCount($owner, $type));
        $this->assertSame(1, $document->fresh()->version);
        $this->assertNotNull($document->fresh()->document_group_id);
    }

    public function test_a_multi_slot_type_keeps_the_attached_document_in_its_own_slot(): void
    {
        $type = $this->makeType(['allows_multiple' => true]);
        $owner = TestModel::create(['name' => 'owner']);
        $existing = $this->service->upload($this->file('first.txt'), $type, $owner);

        $moved = $this->service->attach($this->service->stageUpload($this->file('second.txt'), $type, '42'), $owner);

        $this->assertSame(2, $this->latestCount($owner, $type));
        $this->assertTrue($existing->fresh()->is_latest);
        $this->assertNotSame($existing->document_group_id, $moved->fresh()->document_group_id);
    }

    public function test_exactly_one_latest_per_owner_and_type_when_the_transports_are_mixed(): void
    {
        $this->skipUnlessFakeDiskSupportsUploadUrls();

        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $this->service->upload($this->file('direct.txt'), $type, $owner);
        $this->assertSame(1, $this->latestCount($owner, $type));

        $presigned = $this->service->initiatePresignedUpload($type, 'presigned.bin', '42')['session'];
        Storage::disk('test_disk')->put($presigned->path, 'presigned bytes');
        $this->service->attach($this->service->finalizeDirectUpload($presigned), $owner);
        $this->assertSame(1, $this->latestCount($owner, $type));

        $multipart = $this->service->initiateMultipartUpload($type, 'multi.bin', 'user-1');
        FakeMultipartUploadDriver::uploadPart($multipart->upload_id, 1, 'multipart bytes');
        $this->service->attach($this->service->completeMultipartUpload($multipart), $owner);
        $this->assertSame(1, $this->latestCount($owner, $type));

        $this->service->attach($this->service->stageUpload($this->file('moved.txt'), $type, '42'), $owner);
        $this->assertSame(1, $this->latestCount($owner, $type));

        $this->assertSame(4, Document::where('document_type_id', $type->id)->max('version'));
    }
}
