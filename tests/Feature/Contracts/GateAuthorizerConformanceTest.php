<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Tests\Conformance\AuthorizerConformanceTest;

/**
 * The default authorizer, with abilities that allow some callers and deny others, honours the contract.
 */
class GateAuthorizerConformanceTest extends AuthorizerConformanceTest
{
    protected function authorizer(): AuthorizesDocumentAccess
    {
        return new GateDocumentAuthorizer;
    }

    protected function prepare(): void
    {
        Gate::define('documents.initiate-upload', fn (?Authenticatable $user) => $user?->getAuthIdentifier() === 1);
        Gate::define('documents.finalize', fn (?Authenticatable $user) => $user?->getAuthIdentifier() === 1);
        Gate::define('documents.attach', fn (?Authenticatable $user) => $user?->getAuthIdentifier() === 1);
        Gate::define('documents.delete', fn (?Authenticatable $user) => $user?->getAuthIdentifier() === 1);
        Gate::define('documents.view', fn (?Authenticatable $user, Document $document) => $user !== null && ($user->getAuthIdentifier() === 1 || $document->id === $this->document->id));
    }
}
