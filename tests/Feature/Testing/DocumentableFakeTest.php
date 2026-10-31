<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Testing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Documentable;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Services\MultipartDriverResolver;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\ExpectationFailedException;

class DocumentableFakeTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentType $type;

    protected TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->type = DocumentType::create([
            'code' => 'TEST_DOC',
            'name' => 'Test Doc',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'tests',
            'allows_multiple' => true,
            'requires_versioning' => false,
        ]);
        $this->owner = TestModel::create(['name' => 'Owner']);
    }

    public function test_a_faked_disk_gets_the_fake_multipart_driver(): void
    {
        Documentable::fake(['s3']);

        $this->assertInstanceOf(FakeMultipartUploadDriver::class, app(MultipartDriverResolver::class)->forDisk('s3'));
    }

    public function test_assert_nothing_uploaded_passes_when_empty_and_fails_when_rows_exist(): void
    {
        $fake = Documentable::fake();

        $fake->assertNothingUploaded();

        UploadSession::factory()->create();

        $this->expectException(ExpectationFailedException::class);
        $fake->assertNothingUploaded();
    }

    public function test_assert_nothing_uploaded_after_an_upload_fails(): void
    {
        $restrictedType = DocumentType::create([
            'code' => 'PDF_ONLY',
            'name' => 'PDF Only',
            'max_size_mb' => 1,
            'allowed_extensions' => ['pdf'],
            'disk' => 's3',
            'path_prefix' => 'tests',
        ]);

        $fake = Documentable::fake();

        $service = app(DocumentService::class);

        // Upload a file with a disallowed extension that fails validation
        try {
            $service->upload(
                UploadedFile::fake()->create('malicious.exe', 10),
                $restrictedType,
                $this->owner
            );
            $this->fail('Expected upload to fail validation.');
        } catch (ValidationException) {
            // Expected validation failure
        }

        $fake->assertNothingUploaded();
    }

    public function test_assert_uploaded_passes_and_fails(): void
    {
        $fake = Documentable::fake();

        try {
            $fake->assertUploaded();
            $this->fail('Expected ExpectationFailedException when no uploaded session exists.');
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('uploaded', $e->getMessage());
        }

        $session = UploadSession::factory()->uploaded()->create();

        $fake->assertUploaded();
        $fake->assertUploaded(fn (UploadSession $s) => $s->id === $session->id);

        $this->expectException(ExpectationFailedException::class);
        $fake->assertUploaded(fn (UploadSession $s) => $s->id === 'non-existent-id');
    }

    public function test_assert_multipart_initiated_passes_and_fails(): void
    {
        $fake = Documentable::fake();

        try {
            $fake->assertMultipartInitiated();
            $this->fail('Expected ExpectationFailedException when no multipart upload initiated.');
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('MultipartUploadInitiated', $e->getMessage());
        }

        $service = app(DocumentService::class);
        $session = $service->initiateMultipartUpload($this->type, 'multi.pdf', 'user-123');

        $fake->assertMultipartInitiated();
        $fake->assertMultipartInitiated(fn (UploadSession $initiated) => $initiated->id === $session->id);

        $this->expectException(ExpectationFailedException::class);
        $fake->assertMultipartInitiated(fn (UploadSession $initiated) => $initiated->id === 'someone-else');
    }

    public function test_assert_attached_passes_and_fails(): void
    {
        $fake = Documentable::fake();

        try {
            $fake->assertAttached();
            $this->fail('Expected ExpectationFailedException when no document attached.');
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('DocumentUploaded', $e->getMessage());
        }

        $service = app(DocumentService::class);
        $doc = $service->upload(
            UploadedFile::fake()->createWithContent('file.pdf', "%PDF-1.4\ncontent"),
            $this->type,
            $this->owner
        );

        $fake->assertAttached();
        $fake->assertAttached(fn (Document $d) => $d->id === $doc->id);

        $this->expectException(ExpectationFailedException::class);
        $fake->assertAttached(fn (Document $d) => $d->id === 'wrong-id');
    }

    public function test_assert_purged_passes_and_fails(): void
    {
        $fake = Documentable::fake();

        try {
            $fake->assertPurged();
            $this->fail('Expected ExpectationFailedException when no document purged.');
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString('DocumentPurged', $e->getMessage());
        }

        $service = app(DocumentService::class);
        $doc = $service->upload(
            UploadedFile::fake()->createWithContent('file.pdf', "%PDF-1.4\ncontent"),
            $this->type,
            $this->owner
        );

        $service->purge($doc);

        $fake->assertPurged();
        $fake->assertPurged(fn (Document $d) => $d->id === $doc->id);
    }

    public function test_app_listener_for_document_uploaded_does_not_fire_but_other_listener_fires(): void
    {
        $packageEventFired = false;
        $customEventFired = false;

        Event::listen(DocumentUploaded::class, function () use (&$packageEventFired) {
            $packageEventFired = true;
        });

        Event::listen('custom.test.event', function () use (&$customEventFired) {
            $customEventFired = true;
        });

        Documentable::fake();

        $service = app(DocumentService::class);
        $service->upload(
            UploadedFile::fake()->createWithContent('file.pdf', "%PDF-1.4\ncontent"),
            $this->type,
            $this->owner
        );

        Event::dispatch('custom.test.event');

        $this->assertFalse($packageEventFired, 'Listener for DocumentUploaded should not have fired under fake.');
        $this->assertTrue($customEventFired, 'Listener for custom event should have fired.');
    }

    public function test_failures_say_what_was_expected_and_what_exists(): void
    {
        $fake = Documentable::fake();

        $this->assertFailsWith('none exists. There are 0 session(s) in total.', fn () => $fake->assertUploaded());
        $this->assertFailsWith('[MultipartUploadInitiated] event to have been dispatched, but none was.', fn () => $fake->assertMultipartInitiated());

        UploadSession::factory()->uploaded()->create();
        $this->assertFailsWith('none of the 1 uploaded session(s) matched', fn () => $fake->assertUploaded(fn () => false));
        $this->assertFailsWith('found 1 upload session(s) and 0 document(s)', fn () => $fake->assertNothingUploaded());

        $session = app(DocumentService::class)->initiateMultipartUpload($this->type, 'multi.pdf', 'user-1');
        $this->assertFailsWith('[MultipartUploadInitiated] event matching the callback, but none of the 1 dispatched event(s) matched', fn () => $fake->assertMultipartInitiated(fn () => false));
        $fake->assertMultipartInitiated(fn (UploadSession $initiated) => $initiated->id === $session->id);
    }

    public function test_fake_covers_the_configured_disk_the_type_disks_and_the_configured_types(): void
    {
        config()->set('filesystems.disks.archive', ['driver' => 'local', 'root' => '/nonexistent/archive']);
        config()->set('filesystems.disks.exports', ['driver' => 'local', 'root' => '/nonexistent/exports']);
        config()->set('documentable.disk', 'exports');
        DocumentType::create(['code' => 'ARCHIVED', 'name' => 'Archived', 'max_size_mb' => 1, 'disk' => 'archive', 'path_prefix' => 'a']);

        Documentable::fake();

        foreach (['archive', 'exports'] as $disk) {
            Storage::disk($disk)->put('probe.txt', 'x');
            Storage::disk($disk)->assertExists('probe.txt');
        }
    }

    public function test_fake_starts_the_in_memory_driver_empty_and_registers_it_for_the_driver_of_each_disk(): void
    {
        FakeMultipartUploadDriver::$sessions['left-over'] = ['path' => 'x', 'parts' => []];

        config()->set('filesystems.disks.s3.driver', 's3');

        Documentable::fake(['s3']);

        $this->assertSame([], FakeMultipartUploadDriver::$sessions);
        $this->assertInstanceOf(FakeMultipartUploadDriver::class, app(MultipartDriverResolver::class)->forDisk('s3'));
    }

    private function assertFailsWith(string $needle, \Closure $act): void
    {
        try {
            $act();
        } catch (ExpectationFailedException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }

        $this->fail("Expected a failure containing [{$needle}].");
    }
}
