<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * The default authorizer: every question goes to Laravel's Gate, and anything the app has not defined is
 * denied. Define the abilities with `Gate::define()`:
 *
 *   documents.initiate-upload   (DocumentType $type)
 *   documents.finalize          (UploadSession $session)
 *   documents.attach            (UploadSession|Document $source, Model $owner)
 *   documents.view              (Document $document)
 *   documents.delete            (Document $document)
 *
 * An ability name contains a dot, so a policy cannot hold a method of that name. When the ability is not
 * defined but a policy is registered for the subject, the policy method of the same meaning is asked instead:
 * `initiateUpload` on a DocumentType policy, `finalize` and `attach` on an UploadSession (or Document) policy,
 * `view` and `delete` on a Document policy. Gate `before` and `after` callbacks apply as they do everywhere else.
 *
 * `canViewMany()` calls `viewMany($user, Collection $documents): Collection` on the Document policy when the
 * app has one, so a listing needs one question per page. Without it, each document is checked on its own.
 *
 * @api
 */
class GateDocumentAuthorizer implements AuthorizesDocumentAccess
{
    public function canInitiateUpload(?Authenticatable $user, DocumentType $type): bool
    {
        return $this->allows($user, 'documents.initiate-upload', 'initiateUpload', $type);
    }

    public function canFinalize(?Authenticatable $user, UploadSession $session): bool
    {
        return $this->allows($user, 'documents.finalize', 'finalize', $session);
    }

    public function canAttach(?Authenticatable $user, UploadSession|Document $source, Model $owner): bool
    {
        return $this->allows($user, 'documents.attach', 'attach', $source, $owner);
    }

    public function canView(?Authenticatable $user, Document $document): bool
    {
        return $this->allows($user, 'documents.view', 'view', $document);
    }

    public function canDelete(?Authenticatable $user, Document $document): bool
    {
        return $this->allows($user, 'documents.delete', 'delete', $document);
    }

    public function canViewMany(?Authenticatable $user, Collection $documents): Collection
    {
        // A guest never reaches a policy method (Gate itself denies it unless the method accepts null),
        // so the per-document path below is the one that answers for it.
        $policy = $user === null || $documents->isEmpty() ? null : Gate::getPolicyFor(Document::class);

        if (is_object($policy) && is_callable([$policy, 'viewMany'])) {
            // The hook gets a copy, so a policy that mutates its argument cannot change what is filtered below.
            $viewable = $policy->viewMany($user, clone $documents);
            $allowed = $viewable instanceof Collection
                ? array_flip($viewable->map(fn (Document $document) => (string) $document->getKey())->all())
                : [];

            return $documents->filter(fn (Document $document) => isset($allowed[(string) $document->getKey()]))->values();
        }

        return $documents->filter(fn (Document $document) => $this->canView($user, $document))->values();
    }

    private function allows(?Authenticatable $user, string $ability, string $policyMethod, Model ...$arguments): bool
    {
        $gate = Gate::forUser($user);

        if (! $gate->has($ability)) {
            $policy = Gate::getPolicyFor($arguments[0]);

            if (is_object($policy) && is_callable([$policy, $policyMethod])) {
                return $gate->allows($policyMethod, $arguments);
            }
        }

        return $gate->allows($ability, $arguments);
    }
}
