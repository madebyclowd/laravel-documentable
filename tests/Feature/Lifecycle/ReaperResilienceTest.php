<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Lifecycle;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\FakeMultipartUploadDriver;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;

/**
 * The reaper aborts every expired multipart session on the provider. One session the provider no longer knows
 * (it already expired there, or a lifecycle rule removed it) threw `NoSuchUpload`, which stopped the whole sweep
 * and left every later session, and its billed parts, in place until the next run hit the same wall.
 */
class ReaperResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected DocumentType $type;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');
        config()->set('documentable.multipart.drivers.local', FakeMultipartUploadDriver::class);
        FakeMultipartUploadDriver::reset();

        $this->service = $this->app->make(DocumentService::class);
        $this->type = DocumentType::create(['code' => 'BIG', 'name' => 'Big', 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => 'big']);
    }

    protected function expiredSession(string $user): UploadSession
    {
        $session = $this->service->initiateMultipartUpload($this->type, 'f.bin', $user);
        $session->update(['expires_at' => now()->subDay()]);

        return $session->fresh();
    }

    public function test_a_session_the_provider_no_longer_knows_is_expired_and_the_sweep_continues(): void
    {
        $gone = $this->expiredSession('user-1');
        $other = $this->expiredSession('user-2');
        FakeMultipartUploadDriver::$abortErrors[$gone->upload_id] = new MultipartUploadNotFoundException('NoSuchUpload');

        $this->artisan('documents:clean-orphaned')->assertExitCode(0);

        $this->assertSame(UploadSessionStatus::Expired, $gone->fresh()->status);
        $this->assertSame(UploadSessionStatus::Expired, $other->fresh()->status);
        $this->assertArrayNotHasKey($other->upload_id, FakeMultipartUploadDriver::$sessions);
    }

    public function test_an_unexpected_failure_is_logged_keeps_that_session_open_and_does_not_stop_the_sweep(): void
    {
        $broken = $this->expiredSession('user-1');
        $other = $this->expiredSession('user-2');
        FakeMultipartUploadDriver::$abortErrors[$broken->upload_id] = new RuntimeException('provider unreachable');
        Log::shouldReceive('error')->once();

        $this->artisan('documents:clean-orphaned')->assertExitCode(1);

        $this->assertSame(UploadSessionStatus::Initiated, $broken->fresh()->status);
        $this->assertSame(UploadSessionStatus::Expired, $other->fresh()->status);
        $this->assertArrayNotHasKey($other->upload_id, FakeMultipartUploadDriver::$sessions);
    }

    public function test_a_user_abort_of_an_already_gone_upload_still_ends_the_session(): void
    {
        $session = $this->service->initiateMultipartUpload($this->type, 'f.bin', 'user-1');
        FakeMultipartUploadDriver::$abortErrors[$session->upload_id] = new MultipartUploadNotFoundException('NoSuchUpload');

        $this->service->abortUploadSession($session);

        $this->assertSame(UploadSessionStatus::Aborted, $session->fresh()->status);
    }
}
