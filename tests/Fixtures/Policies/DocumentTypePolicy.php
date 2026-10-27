<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * A policy an app registers for DocumentType: anyone may start an upload of a PUBLIC type, only user 1 any other.
 */
class DocumentTypePolicy
{
    public function initiateUpload(Authenticatable $user, DocumentType $type): bool
    {
        return $type->code === 'PUBLIC' || $user->getAuthIdentifier() === 1;
    }
}
