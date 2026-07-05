<?php

namespace MadeByClowd\Documentable\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Bind your own implementation to control who can upload/view/delete
 * documents. Default (MadeByClowd\Documentable\Defaults\PermissiveDocumentAuthorizer)
 * allows everything — replace before production use. Package doesn't call
 * this internally (DocumentService methods have no request/user context of
 * their own); it's a seam for the consuming app's controller/policy layer to
 * consult before calling into DocumentService.
 */
interface AuthorizesDocumentAccess
{
    public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $documentable): bool;

    public function canView(?Authenticatable $user, Document $document): bool;

    public function canDelete(?Authenticatable $user, Document $document): bool;
}
