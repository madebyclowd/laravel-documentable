<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\ConfigurableAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * The listing cuts a page in SQL in a stable order and asks the authorizer once about that page. `meta.total`
 * counts the owner's latest documents, so a page can be short when some of its documents are hidden.
 */
class DocumentListingPaginationTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $type;

    private TestModel $owner;

    /** @var list<string> ids of the documents the authorizer hides */
    private array $hidden = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.test_disk', ['driver' => 'local']);
        Storage::fake('test_disk');

        $this->type = DocumentType::create([
            'code' => 'MANY',
            'name' => 'Many',
            'max_size_mb' => 10,
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'allows_multiple' => true,
        ]);
        $this->owner = TestModel::create(['name' => 'owner']);
    }

    /**
     * @return Collection<int, Document>
     */
    private function createDocuments(TestModel $owner, int $count): Collection
    {
        $service = app(DocumentService::class);

        return Collection::times($count, fn (int $n) => $service->upload(
            UploadedFile::fake()->createWithContent("doc-{$n}.txt", "content {$n} ".$owner->getKey()),
            $this->type,
            $owner
        ));
    }

    private function hideEveryThird(Collection $documents): void
    {
        $this->hidden = $documents->values()->filter(fn (Document $d, int $i) => $i % 3 === 0)->pluck('id')->all();
    }

    private function authorizer(): ConfigurableAuthorizer
    {
        return $this->useAuthorizer(ConfigurableAuthorizer::with([
            'canViewMany' => fn ($user, Collection $documents) => $documents->reject(fn (Document $d) => in_array($d->id, $this->hidden, true))->values(),
        ]));
    }

    private function url(TestModel $owner, int $page): string
    {
        return '/documents?documentable_type='.$owner->getMorphClass().'&documentable_id='.$owner->getKey().'&page='.$page;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $data
     * @return list<string>
     */
    private function idsIn(array $data): array
    {
        return collect($data)->flatMap(fn ($groups) => collect($groups)->pluck('id')->values())->all();
    }

    /**
     * @return list<string>
     */
    private function databaseOrder(): array
    {
        return Document::where('documentable_type', $this->owner->getMorphClass())
            ->where('documentable_id', (string) $this->owner->getKey())
            ->whereNotNull('latest_marker')
            ->orderBy('document_type_id')->orderBy('document_group_id')->orderBy('id')
            ->pluck('id')->all();
    }

    public function test_pages_of_twenty_five_with_hidden_documents_are_correct_and_stable(): void
    {
        config()->set('documentable.listing.per_page', 25);
        $documents = $this->createDocuments($this->owner, 60);
        $this->hideEveryThird($documents);
        $this->authorizer();
        $order = $this->databaseOrder();

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $response = $this->getJson($this->url($this->owner, $page))->assertOk();
            $pageRows = array_slice($order, ($page - 1) * 25, 25);
            $expected = array_values(array_diff($pageRows, $this->hidden));

            $this->assertSame($expected, $this->idsIn($response->json('data')), "page {$page} holds its own rows, minus the hidden ones, in database order");
            $this->assertSame(
                ['current_page' => $page, 'per_page' => 25, 'total' => 60, 'last_page' => 3],
                $response->json('meta')
            );

            $seen = [...$seen, ...$this->idsIn($response->json('data'))];
        }

        $this->assertSame(array_values(array_diff($order, $this->hidden)), $seen, 'every viewable document appears exactly once across the pages');
        $this->assertSame($seen, array_values(array_unique($seen)));
    }

    public function test_the_same_page_comes_back_in_the_same_order(): void
    {
        config()->set('documentable.listing.per_page', 25);
        $this->createDocuments($this->owner, 30);
        $this->authorizer();

        $first = $this->idsIn($this->getJson($this->url($this->owner, 1))->json('data'));
        $again = $this->idsIn($this->getJson($this->url($this->owner, 1))->json('data'));

        $this->assertSame($first, $again);
        $this->assertSame(array_slice($this->databaseOrder(), 0, 25), $first);
    }

    public function test_a_page_past_the_end_is_empty_and_total_is_still_the_whole_count(): void
    {
        config()->set('documentable.listing.per_page', 25);
        $this->createDocuments($this->owner, 30);
        $this->authorizer();

        $response = $this->getJson($this->url($this->owner, 9))->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(30, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    public function test_a_page_whose_documents_are_all_hidden_is_empty_but_counted_in_total(): void
    {
        config()->set('documentable.listing.per_page', 5);
        $documents = $this->createDocuments($this->owner, 8);
        $this->hidden = $documents->pluck('id')->all();
        $this->authorizer();

        $response = $this->getJson($this->url($this->owner, 1))->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(8, $response->json('meta.total'));
    }

    public function test_the_authorizer_is_asked_once_for_the_page_and_no_query_runs_per_document(): void
    {
        config()->set('documentable.listing.per_page', 25);
        $this->createDocuments($this->owner, 25);
        $small = TestModel::create(['name' => 'small']);
        $this->createDocuments($small, 2);

        $authorizer = $this->authorizer();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->url($this->owner, 1))->assertOk();
        $queriesForTwentyFive = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson($this->url($small, 1))->assertOk();
        $queriesForTwo = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($queriesForTwo, $queriesForTwentyFive, 'the query count does not grow with the page');
        $this->assertSame(['canViewMany', 'canViewMany'], $authorizer->asked, 'one question per request, never one per document');
    }

    public function test_the_response_shape_is_unchanged(): void
    {
        $documents = $this->createDocuments($this->owner, 2);
        $this->authorizer();

        $response = $this->getJson($this->url($this->owner, 1))->assertOk();

        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        $this->assertSame(['current_page', 'per_page', 'total', 'last_page'], array_keys($response->json('meta')));

        $typeKey = $this->type->id;
        $groupKey = $documents->first()->document_group_id;
        $this->assertArrayHasKey($typeKey, $response->json('data'));
        $this->assertArrayHasKey($groupKey, $response->json('data')[$typeKey]);
        $this->assertNotNull($response->json("data.{$typeKey}.{$groupKey}.storage_file.mime_type"));
    }
}
