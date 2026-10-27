<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * `listForOwner()` stays public for apps that list documents themselves; the HTTP listing now pages through
 * `pageForOwner()`. Both answer the same question: the latest document of each of an owner's groups.
 */
class ListForOwnerTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        $this->service = $this->app->make(DocumentService::class);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::create(['code' => $code, 'name' => $code, 'max_size_mb' => 10, 'disk' => 'test_disk', 'path_prefix' => strtolower($code), 'allows_multiple' => true]);
    }

    private function upload(DocumentType $type, TestModel $owner, string $content): void
    {
        $this->service->upload(UploadedFile::fake()->createWithContent("{$content}.txt", $content), $type, $owner);
    }

    public function test_it_lists_the_latest_document_of_every_group_with_its_stored_file(): void
    {
        $owner = TestModel::create(['name' => 'owner']);
        $invoice = $this->type('INVOICE');
        $this->upload($invoice, $owner, 'one');
        $this->upload($invoice, $owner, 'two');
        TestModel::create(['name' => 'someone else']);

        $documents = $this->service->listForOwner($owner);

        $this->assertCount(2, $documents);
        $this->assertTrue($documents->every(fn ($document) => $document->relationLoaded('storageFile')));
    }

    public function test_it_can_be_narrowed_to_one_type(): void
    {
        $owner = TestModel::create(['name' => 'owner']);
        $invoice = $this->type('INVOICE');
        $receipt = $this->type('RECEIPT');
        $this->upload($invoice, $owner, 'one');
        $this->upload($receipt, $owner, 'two');

        $documents = $this->service->listForOwner($owner, $receipt->id);

        $this->assertCount(1, $documents);
        $this->assertSame($receipt->id, $documents->first()->document_type_id);
    }

    public function test_a_page_counts_the_whole_listing_and_holds_only_its_share(): void
    {
        $owner = TestModel::create(['name' => 'owner']);
        $type = $this->type('INVOICE');
        foreach (['a', 'b', 'c'] as $content) {
            $this->upload($type, $owner, $content);
        }

        $page = $this->service->pageForOwner($owner, null, 2, 2);

        $this->assertSame(3, $page->total());
        $this->assertCount(1, $page->items());
    }
}
