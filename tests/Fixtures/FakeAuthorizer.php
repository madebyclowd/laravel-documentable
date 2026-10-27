<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * Denies everything.
 */
class FakeAuthorizer implements AuthorizesDocumentAccess
{
    public function canInitiateUpload(?Authenticatable $user, DocumentType $type): bool
    {
        return false;
    }

    public function canFinalize(?Authenticatable $user, UploadSession $session): bool
    {
        return false;
    }

    public function canAttach(?Authenticatable $user, UploadSession|Document $source, Model $owner): bool
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

    public function canViewMany(?Authenticatable $user, Collection $documents): Collection
    {
        return new Collection;
    }
}
