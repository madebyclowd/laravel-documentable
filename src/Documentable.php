<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Events\DocumentDeleted;
use MadeByClowd\Documentable\Events\DocumentProcessed;
use MadeByClowd\Documentable\Events\DocumentPurged;
use MadeByClowd\Documentable\Events\DocumentRejected;
use MadeByClowd\Documentable\Events\DocumentUploaded;
use MadeByClowd\Documentable\Events\DocumentVersionPromoted;
use MadeByClowd\Documentable\Events\DocumentVersionPruned;
use MadeByClowd\Documentable\Events\DocumentVersionSuperseded;
use MadeByClowd\Documentable\Events\MultipartUploadAborted;
use MadeByClowd\Documentable\Events\MultipartUploadInitiated;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Testing\DocumentableFake;
use MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver;
use Throwable;

/**
 * Public entry point for testing documentable operations in applications.
 *
 * @api
 */
final class Documentable
{
    /**
     * Fake the storage disks, multipart driver, and package domain events.
     *
     * @param  array<int, string>|null  $disks
     */
    public static function fake(?array $disks = null): DocumentableFake
    {
        if ($disks === null) {
            $disks = [(string) config('documentable.disk', 'local')];

            try {
                /** @var list<string> $dbDisks */
                $dbDisks = DocumentType::query()->distinct()->pluck('disk')->all();
                $disks = array_merge($disks, $dbDisks);
            } catch (Throwable) {
                // No documentable tables yet (a test that never migrated): fake only the configured disks.
            }

            $configuredTypes = (array) config('documentable.types', []);
            foreach ($configuredTypes as $typeDef) {
                if (is_array($typeDef) && ! empty($typeDef['disk'])) {
                    $disks[] = (string) $typeDef['disk'];
                }
            }

            $disks = array_values(array_unique(array_filter($disks)));
        }

        foreach ($disks as $disk) {
            Storage::fake($disk);
            config()->set('documentable.multipart.drivers.'.config("filesystems.disks.{$disk}.driver"), FakeMultipartUploadDriver::class);
        }

        FakeMultipartUploadDriver::reset();

        Event::fake([
            MultipartUploadInitiated::class,
            MultipartUploadAborted::class,
            DocumentUploaded::class,
            DocumentDeleted::class,
            DocumentPurged::class,
            DocumentProcessed::class,
            DocumentRejected::class,
            DocumentVersionPromoted::class,
            DocumentVersionPruned::class,
            DocumentVersionSuperseded::class,
        ]);

        return new DocumentableFake;
    }
}
