<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Contracts;

use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Tests\Conformance\AuthorizerConformanceTest;
use MadeByClowd\Documentable\Tests\Fixtures\AllowAllAuthorizer;

/**
 * The fixture the rest of the suite relies on is itself a conforming authorizer.
 */
class AllowAllAuthorizerConformanceTest extends AuthorizerConformanceTest
{
    protected function authorizer(): AuthorizesDocumentAccess
    {
        return new AllowAllAuthorizer;
    }
}
