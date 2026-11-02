<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\Policies\BrokenBatchPolicy;
use MadeByClowd\Documentable\Tests\Fixtures\Policies\DocumentPolicy;
use MadeByClowd\Documentable\Tests\Fixtures\Policies\DocumentTypePolicy;
use MadeByClowd\Documentable\Tests\Fixtures\Policies\OverreachingBatchPolicy;
use MadeByClowd\Documentable\Tests\Fixtures\Policies\UploadSessionPolicy;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The default authorizer maps each contract method onto a Gate ability, or a policy method when the app registered
 * a policy instead, and denies whatever is undefined. User 1 is the admin; user 2 an ordinary user.
 */
class GateDocumentAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    private GateDocumentAuthorizer $authorizer;

    private DocumentType $public;

    private DocumentType $restricted;

    private TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('filesystems.disks.gate_disk', ['driver' => 'local']);
        Storage::fake('gate_disk');
        DocumentPolicy::reset();

        $this->authorizer = new GateDocumentAuthorizer;
        $this->public = $this->makeType('PUBLIC');
        $this->restricted = $this->makeType('RESTRICTED');
        $this->owner = TestModel::create(['name' => 'owner']);
    }

    private function makeType(string $code): DocumentType
    {
        return DocumentType::create(['code' => $code, 'name' => $code, 'max_size_mb' => 10, 'disk' => 'gate_disk', 'path_prefix' => 'docs']);
    }

    private function user(int $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    private function uploadSession(DocumentType $type, ?string $userId): UploadSession
    {
        return app(DocumentService::class)->stageUpload(UploadedFile::fake()->createWithContent('s.txt', 'staged'), $type, $userId);
    }

    private function document(DocumentType $type, string $name = 'd.txt'): Document
    {
        return app(DocumentService::class)->upload(UploadedFile::fake()->createWithContent($name, $name), $type, $this->owner);
    }

    private function defineGates(): void
    {
        Gate::define('documents.initiate-upload', fn (?Authenticatable $user, DocumentType $type) => $user !== null && ($type->code === 'PUBLIC' || $user->getAuthIdentifier() === 1));
        Gate::define('documents.finalize', fn (?Authenticatable $user, UploadSession $session) => $user !== null && (string) $user->getAuthIdentifier() === $session->user_id);
        Gate::define('documents.attach', fn (?Authenticatable $user, UploadSession|Document $source, Model $owner) => $user?->getAuthIdentifier() === 1);
        Gate::define('documents.view', fn (?Authenticatable $user, Document $document) => $user !== null && ($user->getAuthIdentifier() === 1 || $document->document_type_id === $this->public->id));
        Gate::define('documents.delete', fn (?Authenticatable $user, Document $document) => $user?->getAuthIdentifier() === 1);
    }

    private function registerPolicies(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(UploadSession::class, UploadSessionPolicy::class);
        Gate::policy(DocumentType::class, DocumentTypePolicy::class);
        DocumentPolicy::$visibleType = $this->public->id;
    }

    /**
     * Both ways of saying the same rules, so every case below runs against Gate::define() and against policies.
     *
     * @return array<string, array{0: string}>
     */
    public static function styles(): array
    {
        return ['Gate::define' => ['gates'], 'policies' => ['policies']];
    }

    private function arrange(string $style): void
    {
        $style === 'gates' ? $this->defineGates() : $this->registerPolicies();
    }

    public function test_nothing_is_allowed_while_the_app_has_defined_nothing(): void
    {
        $admin = $this->user(1);
        $session = $this->uploadSession($this->public, '1');
        $document = $this->document($this->public);

        $this->assertFalse($this->authorizer->canInitiateUpload($admin, $this->public));
        $this->assertFalse($this->authorizer->canFinalize($admin, $session));
        $this->assertFalse($this->authorizer->canAttach($admin, $session, $this->owner));
        $this->assertFalse($this->authorizer->canAttach($admin, $document, $this->owner));
        $this->assertFalse($this->authorizer->canView($admin, $document));
        $this->assertFalse($this->authorizer->canDelete($admin, $document));
        $this->assertCount(0, $this->authorizer->canViewMany($admin, new Collection([$document])));
        $this->assertFalse($this->authorizer->canInitiateUpload(null, $this->public));
    }

    public function test_an_ability_the_app_did_not_define_is_denied_even_when_others_are(): void
    {
        Gate::define('documents.view', fn () => true);

        $this->assertTrue($this->authorizer->canView($this->user(2), $this->document($this->public)));
        $this->assertFalse($this->authorizer->canDelete($this->user(2), $this->document($this->public, 'e.txt')));
    }

    #[DataProvider('styles')]
    public function test_initiate_upload(string $style): void
    {
        $this->arrange($style);

        $this->assertTrue($this->authorizer->canInitiateUpload($this->user(1), $this->restricted), 'allowed');
        $this->assertFalse($this->authorizer->canInitiateUpload($this->user(2), $this->restricted), 'denied');
        $this->assertFalse($this->authorizer->canInitiateUpload(null, $this->public), 'unauthenticated');
        $this->assertTrue($this->authorizer->canInitiateUpload($this->user(2), $this->public), 'right type');
        $this->assertFalse($this->authorizer->canInitiateUpload($this->user(2), $this->restricted), 'wrong type');
    }

    #[DataProvider('styles')]
    public function test_finalize(string $style): void
    {
        $this->arrange($style);
        $mine = $this->uploadSession($this->public, '2');

        $this->assertTrue($this->authorizer->canFinalize($this->user(2), $mine), 'allowed');
        $this->assertFalse($this->authorizer->canFinalize($this->user(3), $mine), 'denied');
        $this->assertFalse($this->authorizer->canFinalize(null, $mine), 'unauthenticated');
        $this->assertFalse($this->authorizer->canFinalize($this->user(2), $this->uploadSession($this->restricted, '9')), 'wrong session owner');
        $this->assertFalse($this->authorizer->canFinalize($this->user(2), $this->uploadSession($this->restricted, null)), 'anonymous session');
    }

    #[DataProvider('styles')]
    public function test_attach_for_a_session_and_for_a_document(string $style): void
    {
        $this->arrange($style);
        $session = $this->uploadSession($this->public, '2');
        $document = $this->document($this->public);

        $this->assertTrue($this->authorizer->canAttach($this->user(1), $session, $this->owner), 'session, allowed');
        $this->assertTrue($this->authorizer->canAttach($this->user(1), $document, $this->owner), 'document, allowed');
        $this->assertFalse($this->authorizer->canAttach($this->user(2), $session, $this->owner), 'session, denied');
        $this->assertFalse($this->authorizer->canAttach($this->user(2), $document, $this->owner), 'document, denied');
        $this->assertFalse($this->authorizer->canAttach(null, $session, $this->owner), 'unauthenticated');
    }

    #[DataProvider('styles')]
    public function test_view_and_delete(string $style): void
    {
        $this->arrange($style);
        $open = $this->document($this->public);
        $hidden = $this->document($this->restricted, 'h.txt');

        $this->assertTrue($this->authorizer->canView($this->user(2), $open), 'allowed');
        $this->assertFalse($this->authorizer->canView($this->user(2), $hidden), 'denied, wrong type');
        $this->assertTrue($this->authorizer->canView($this->user(1), $hidden), 'admin');
        $this->assertFalse($this->authorizer->canView(null, $open), 'unauthenticated');

        $this->assertTrue($this->authorizer->canDelete($this->user(1), $open), 'allowed');
        $this->assertFalse($this->authorizer->canDelete($this->user(2), $open), 'denied');
        $this->assertFalse($this->authorizer->canDelete(null, $open), 'unauthenticated');
    }

    #[DataProvider('styles')]
    public function test_view_many_returns_the_viewable_subset_in_order(string $style): void
    {
        $this->arrange($style);
        $first = $this->document($this->public, 'a.txt');
        $hidden = $this->document($this->restricted, 'b.txt');
        $last = $this->document($this->public, 'c.txt');
        $documents = new Collection([$first, $hidden, $last]);

        $this->assertSame([$first->id, $last->id], $this->authorizer->canViewMany($this->user(2), $documents)->pluck('id')->all());
        $this->assertSame([$first->id, $hidden->id, $last->id], $this->authorizer->canViewMany($this->user(1), $documents)->pluck('id')->all());
        $this->assertCount(0, $this->authorizer->canViewMany(null, $documents));
        $this->assertCount(0, $this->authorizer->canViewMany($this->user(2), new Collection));
    }

    public function test_a_policy_with_a_batch_hook_is_asked_once_for_the_whole_collection(): void
    {
        $this->registerPolicies();
        $documents = new Collection([$this->document($this->public, 'a.txt'), $this->document($this->restricted, 'b.txt'), $this->document($this->public, 'c.txt')]);

        $viewable = $this->authorizer->canViewMany($this->user(2), $documents);

        $this->assertCount(2, $viewable);
        $this->assertSame(['viewMany' => 1], DocumentPolicy::$calls);
    }

    public function test_without_a_batch_hook_each_document_is_checked_on_its_own(): void
    {
        $calls = 0;
        Gate::define('documents.view', function () use (&$calls) {
            $calls++;

            return true;
        });
        $documents = new Collection([$this->document($this->public, 'a.txt'), $this->document($this->public, 'b.txt'), $this->document($this->public, 'c.txt')]);

        $this->assertCount(3, $this->authorizer->canViewMany($this->user(2), $documents));
        $this->assertSame(3, $calls);
    }

    public function test_a_guest_skips_the_batch_hook_and_is_checked_per_document(): void
    {
        $this->registerPolicies();

        $this->assertCount(0, $this->authorizer->canViewMany(null, new Collection([$this->document($this->public)])));
        $this->assertArrayNotHasKey('viewMany', DocumentPolicy::$calls);
    }

    public function test_a_batch_hook_cannot_add_documents_that_were_not_passed_in(): void
    {
        $passed = $this->document($this->public, 'a.txt');
        OverreachingBatchPolicy::$stranger = $this->document($this->public, 'b.txt');
        Gate::policy(Document::class, OverreachingBatchPolicy::class);

        $viewable = $this->authorizer->canViewMany($this->user(1), new Collection([$passed]));

        $this->assertSame([$passed->id], $viewable->pluck('id')->all());
    }

    public function test_a_batch_hook_that_does_not_return_a_collection_shows_nothing(): void
    {
        Gate::policy(Document::class, BrokenBatchPolicy::class);

        $this->assertCount(0, $this->authorizer->canViewMany($this->user(1), new Collection([$this->document($this->public)])));
    }

    public function test_a_defined_ability_wins_over_a_policy_method(): void
    {
        $this->registerPolicies();
        Gate::define('documents.delete', fn () => false);

        $this->assertFalse($this->authorizer->canDelete($this->user(1), $this->document($this->public)));
    }

    public function test_gate_before_callbacks_apply(): void
    {
        Gate::before(fn (?Authenticatable $user) => $user?->getAuthIdentifier() === 99 ? true : null);

        $this->assertTrue($this->authorizer->canDelete($this->user(99), $this->document($this->public)));
        $this->assertFalse($this->authorizer->canDelete($this->user(2), $this->document($this->public, 'e.txt')));
    }
}
