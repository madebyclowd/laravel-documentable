<?php

namespace MadeByClowd\Documentable\Defaults;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Allows everything. Package ships usable out of the box; production use
 * should bind a real implementation via config('documentable.authorization.resolver').
 */
class PermissiveDocumentAuthorizer implements AuthorizesDocumentAccess
{
    public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $documentable): bool
    {
        return true;
    }

    public function canView(?Authenticatable $user, Document $document): bool
    {
        return true;
    }

    public function canDelete(?Authenticatable $user, Document $document): bool
    {
        return true;
    }
}
