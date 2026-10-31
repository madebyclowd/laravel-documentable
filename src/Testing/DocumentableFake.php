<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Testing;

use Illuminate\Support\Facades\Event;
use MadeByClowd\Documentable\Enums\UploadSessionStatus;
use MadeByClowd\Documentable\Events\DocumentPurged;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Events\MultipartUploadInitiated;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\UploadSession;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * Assertions for code that stores documents, returned by `Documentable::fake()`.
 *
 * @api
 */
final class DocumentableFake
{
    /**
     * An upload session in status `uploaded` exists.
     *
     * @param  (callable(UploadSession): bool)|null  $callback
     */
    public function assertUploaded(?callable $callback = null): self
    {
        $sessions = UploadSession::query()->where('status', UploadSessionStatus::Uploaded)->get();

        if ($sessions->isEmpty()) {
            throw new ExpectationFailedException('Expected an upload session in status [uploaded], but none exists. There are '.UploadSession::query()->count().' session(s) in total.');
        }

        if ($callback !== null && ! $sessions->contains(fn (UploadSession $session) => $callback($session))) {
            throw new ExpectationFailedException("Expected an uploaded session matching the callback, but none of the {$sessions->count()} uploaded session(s) matched.");
        }

        Assert::assertNotEmpty($sessions);

        return $this;
    }

    /**
     * A multipart upload was started.
     *
     * @param  (callable(UploadSession): bool)|null  $callback
     */
    public function assertMultipartInitiated(?callable $callback = null): self
    {
        return $this->assertDispatched(MultipartUploadInitiated::class, $callback === null ? null : fn (MultipartUploadInitiated $event) => $callback($event->session));
    }

    /**
     * A document was attached to an owner.
     *
     * @param  (callable(Document): bool)|null  $callback
     */
    public function assertAttached(?callable $callback = null): self
    {
        return $this->assertDispatched(DocumentUploaded::class, $callback === null ? null : fn (DocumentUploaded $event) => $callback($event->document));
    }

    /**
     * A document was permanently deleted.
     *
     * @param  (callable(Document): bool)|null  $callback
     */
    public function assertPurged(?callable $callback = null): self
    {
        return $this->assertDispatched(DocumentPurged::class, $callback === null ? null : fn (DocumentPurged $event) => $callback($event->document));
    }

    public function assertNothingUploaded(): self
    {
        $sessions = UploadSession::query()->count();
        $documents = Document::query()->count();

        if ($sessions > 0 || $documents > 0) {
            throw new ExpectationFailedException("Expected no upload sessions and no documents, but found {$sessions} upload session(s) and {$documents} document(s).");
        }

        Assert::assertSame(0, $sessions + $documents);

        return $this;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $event
     * @param  (callable(T): bool)|null  $matches
     */
    private function assertDispatched(string $event, ?callable $matches): self
    {
        $dispatched = Event::dispatched($event);
        $name = class_basename($event);

        if ($dispatched->isEmpty()) {
            throw new ExpectationFailedException("Expected a [{$name}] event to have been dispatched, but none was.");
        }

        if ($matches !== null && ! $dispatched->contains(fn (array $arguments) => $matches($arguments[0]))) {
            throw new ExpectationFailedException("Expected a [{$name}] event matching the callback, but none of the {$dispatched->count()} dispatched event(s) matched.");
        }

        Assert::assertNotEmpty($dispatched);

        return $this;
    }
}
