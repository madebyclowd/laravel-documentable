<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Services\ObjectVerifier;
use MadeByClowd\Documentable\Services\UrlSigner;
use MadeByClowd\Documentable\Tests\TestCase;
use RuntimeException;

/**
 * Failure paths of the collaborators that used to be unguarded inside DocumentService: they now fail with a
 * clear exception instead of a TypeError on null.
 */
class ServiceGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hashing_an_object_that_is_not_on_the_disk_throws_a_clear_exception(): void
    {
        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not open [missing/file.bin] on disk [test_disk] for reading.');

        $this->app->make(ObjectVerifier::class)->hashAndDetectMime('test_disk', 'missing/file.bin');
    }

    public function test_signing_a_url_for_a_document_without_a_storage_file_throws_a_logic_exception(): void
    {
        $document = new Document;
        $document->id = 'doc-1';
        $document->setRelation('storageFile', null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Document doc-1 has no storage file to sign a URL for.');

        $this->app->make(UrlSigner::class)->sign($document, now()->addMinute());
    }

    public function test_sanitizing_a_filename_is_stable_for_empty_input(): void
    {
        $this->assertSame('', UrlSigner::sanitizeHeaderFilename(''));
    }
}
