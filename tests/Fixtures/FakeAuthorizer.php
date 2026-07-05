<?php

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;

class FakeAuthorizer implements AuthorizesDocumentAccess
{
    public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $documentable): bool
    {
        return false;
    }

    public function canView(?Authenticatable $user, Document $document): bool
    {
        return false;
    }

    public function canDelete(?Authenticatable $user, Document $document): bool
    {
        return false;
    }
}
