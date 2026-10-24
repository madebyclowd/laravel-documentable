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
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * A detached document that is reassociated to an owner already holding a document of the same single-slot type
 * used to become a second `is_latest` row: the previous one was never demoted, so the owner had two "current"
 * documents and every "latest" lookup picked one arbitrarily. Reassociating must go through the same slot and
 * version resolution as an upload.
 */
class ReassociateVersioningTest extends TestCase
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

    public function test_reassociating_into_an_occupied_single_slot_keeps_exactly_one_latest_and_bumps_the_version(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $existing = $this->service->upload($this->file('first.txt'), $type, $owner);
        $detached = $this->service->uploadDetached($this->file('second.txt'), $type, [], pending: true);

        Event::fake([DocumentVersionSuperseded::class]);

        $moved = $this->service->reassociateDocument($detached, $owner);
        $moved->commit();

        $this->assertSame(1, $this->latestCount($owner, $type));
        $this->assertSame(2, $moved->fresh()->version);
        $this->assertTrue($moved->fresh()->is_latest);
        $this->assertSame($existing->document_group_id, $moved->fresh()->document_group_id);
        $this->assertFalse($existing->fresh()->is_latest);
        $this->assertSame('committed', $moved->fresh()->status);
        Event::assertDispatched(DocumentVersionSuperseded::class, fn ($event) => $event->previous->is($existing) && $event->new->is($moved));
    }

    public function test_a_hard_replace_type_soft_deletes_the_document_it_replaces(): void
    {
        $type = $this->makeType(['requires_versioning' => false]);
        $owner = TestModel::create(['name' => 'owner']);
        $existing = $this->service->upload($this->file('first.txt'), $type, $owner);
        $detached = $this->service->uploadDetached($this->file('second.txt'), $type);

        $moved = $this->service->reassociateDocument($detached, $owner);

        $this->assertSoftDeleted($existing);
        $this->assertSame(1, $this->latestCount($owner, $type));
        $this->assertSame(1, $moved->fresh()->version);
    }

    public function test_reassociating_into_an_empty_slot_keeps_the_document_as_is(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $detached = $this->service->uploadDetached($this->file('only.txt'), $type);
        $group = $detached->document_group_id;

        $moved = $this->service->reassociateDocument($detached, $owner);

        $this->assertSame(1, $this->latestCount($owner, $type));
        $this->assertSame(1, $moved->fresh()->version);
        $this->assertSame($group, $moved->fresh()->document_group_id);
    }

    public function test_a_multi_slot_type_keeps_the_moved_document_in_its_own_slot(): void
    {
        $type = $this->makeType(['allows_multiple' => true]);
        $owner = TestModel::create(['name' => 'owner']);
        $existing = $this->service->upload($this->file('first.txt'), $type, $owner);
        $detached = $this->service->uploadDetached($this->file('second.txt'), $type);

        $moved = $this->service->reassociateDocument($detached, $owner);

        $this->assertSame(2, $this->latestCount($owner, $type));
        $this->assertTrue($existing->fresh()->is_latest);
        $this->assertNotSame($existing->document_group_id, $moved->fresh()->document_group_id);
    }

    public function test_reassociating_to_the_current_owner_changes_nothing(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);
        $document = $this->service->upload($this->file('first.txt'), $type, $owner);

        $this->service->reassociateDocument($document, $owner);

        $this->assertSame(1, $this->latestCount($owner, $type));
        $this->assertSame(1, $document->fresh()->version);
    }

    public function test_exactly_one_latest_per_owner_and_type_whatever_the_transport(): void
    {
        $type = $this->makeType();
        $owner = TestModel::create(['name' => 'owner']);

        $this->service->upload($this->file('direct.txt'), $type, $owner);
        $this->assertSame(1, $this->latestCount($owner, $type));

        Storage::disk('test_disk')->put('uploads/presigned.bin', 'presigned bytes');
        $this->service->finalizeDirectUpload('uploads/presigned.bin', $type, $owner, 'presigned.bin');
        $this->assertSame(1, $this->latestCount($owner, $type));

        $session = $this->service->initiateMultipartUpload('multi.bin', $type, 'user-1');
        FakeMultipartUploadDriver::uploadPart($session['upload_id'], 1, 'multipart bytes');
        $this->service->completeMultipartUpload($session['path'], $session['upload_id'], 'user-1', $type, $owner, 'multi.bin');
        $this->assertSame(1, $this->latestCount($owner, $type));

        $this->service->reassociateDocument($this->service->uploadDetached($this->file('moved.txt'), $type), $owner);
        $this->assertSame(1, $this->latestCount($owner, $type));

        $this->assertSame(4, Document::where('document_type_id', $type->id)->max('version'));
    }
}
