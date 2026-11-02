<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Conformance;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Proves an authorizer honours the AuthorizesDocumentAccess contract, whatever policy it implements. It does not
 * say who should be allowed; it checks what every implementation owes its callers:
 *
 *  - every question gets a boolean answer for every actor, a guest included, and never throws;
 *  - the same question gets the same answer twice;
 *  - `canAttach()` takes an uploaded session, a stored document and the unsaved document of a direct upload;
 *  - `canViewMany()` returns exactly the documents `canView()` allows, in the order given, never one that was
 *    not passed in, and leaves its input alone.
 *
 * Extend it, return your authorizer from `authorizer()` and, if it needs setup (Gates, policies, rows), do it
 * in `prepare()`. Add `actors()` to include the users your rules distinguish.
 */
abstract class AuthorizerConformanceTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentType $type;

    protected DocumentType $otherType;

    protected TestModel $owner;

    protected UploadSession $session;

    protected Document $document;

    protected Document $otherDocument;

    abstract protected function authorizer(): AuthorizesDocumentAccess;

    /**
     * Runs after the fixtures below exist and before any question is asked.
     */
    protected function prepare(): void {}

    /**
     * The callers every question is asked of. A guest is always included.
     *
     * @return list<Authenticatable>
     */
    protected function actors(): array
    {
        return [(new User)->forceFill(['id' => 1]), (new User)->forceFill(['id' => 2])];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.conformance_disk', ['driver' => 'local']);
        Storage::fake('conformance_disk');

        $this->type = $this->makeType('FIRST');
        $this->otherType = $this->makeType('SECOND');
        $this->owner = TestModel::create(['name' => 'owner']);

        $service = $this->app->make(DocumentService::class);
        $this->session = $service->stageUpload(UploadedFile::fake()->createWithContent('staged.txt', 'staged'), $this->type, '1');
        $this->document = $service->upload(UploadedFile::fake()->createWithContent('first.txt', 'first'), $this->type, $this->owner);
        $this->otherDocument = $service->upload(UploadedFile::fake()->createWithContent('second.txt', 'second'), $this->otherType, $this->owner);

        $this->prepare();
    }

    /**
     * @return list<Authenticatable|null>
     */
    private function callers(): array
    {
        return [null, ...$this->actors()];
    }

    private function makeType(string $code): DocumentType
    {
        return DocumentType::create([
            'code' => $code,
            'name' => $code,
            'max_size_mb' => 10,
            'disk' => 'conformance_disk',
            'path_prefix' => 'docs',
        ]);
    }

    public function test_every_question_is_answered_for_every_caller_without_throwing(): void
    {
        $authorizer = $this->authorizer();
        $unsaved = new Document(['document_type_id' => $this->type->id]);
        $answered = 0;

        foreach ($this->callers() as $user) {
            foreach ([
                $authorizer->canInitiateUpload($user, $this->type),
                $authorizer->canFinalize($user, $this->session),
                $authorizer->canAttach($user, $this->session, $this->owner),
                $authorizer->canAttach($user, $this->document, $this->owner),
                $authorizer->canAttach($user, $unsaved, $this->owner),
                $authorizer->canView($user, $this->document),
                $authorizer->canDelete($user, $this->document),
            ] as $answer) {
                $this->assertIsBool($answer);
                $answered++;
            }
        }

        $this->assertSame(7 * count($this->callers()), $answered);
    }

    public function test_the_same_question_gets_the_same_answer_twice(): void
    {
        $authorizer = $this->authorizer();

        foreach ($this->callers() as $user) {
            $this->assertSame($authorizer->canInitiateUpload($user, $this->type), $authorizer->canInitiateUpload($user, $this->type));
            $this->assertSame($authorizer->canFinalize($user, $this->session), $authorizer->canFinalize($user, $this->session));
            $this->assertSame($authorizer->canView($user, $this->document), $authorizer->canView($user, $this->document));
            $this->assertSame($authorizer->canDelete($user, $this->document), $authorizer->canDelete($user, $this->document));
        }
    }

    public function test_can_view_many_returns_exactly_what_can_view_allows_in_the_order_given(): void
    {
        $authorizer = $this->authorizer();
        $documents = new Collection([$this->otherDocument, $this->document]);

        foreach ($this->callers() as $user) {
            $expected = $documents->filter(fn (Document $document) => $authorizer->canView($user, $document))->pluck('id')->values()->all();

            $this->assertSame($expected, $authorizer->canViewMany($user, $documents)->pluck('id')->values()->all());
        }
    }

    public function test_can_view_many_never_returns_a_document_that_was_not_passed_in(): void
    {
        $authorizer = $this->authorizer();
        $only = new Collection([$this->document]);

        foreach ($this->callers() as $user) {
            $this->assertSame([], $authorizer->canViewMany($user, $only)->pluck('id')->diff([$this->document->id])->values()->all());
        }
    }

    public function test_can_view_many_of_nothing_is_nothing(): void
    {
        foreach ($this->callers() as $user) {
            $this->assertCount(0, $this->authorizer()->canViewMany($user, new Collection));
        }
    }

    public function test_can_view_many_leaves_its_input_alone(): void
    {
        $authorizer = $this->authorizer();
        $documents = new Collection([$this->document, $this->otherDocument]);

        foreach ($this->callers() as $user) {
            $authorizer->canViewMany($user, $documents);

            $this->assertSame([$this->document->id, $this->otherDocument->id], $documents->pluck('id')->all());
        }
    }
}
