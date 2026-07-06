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
    /**
     * $documentable is null when called from storeDetached() (HTTP:
     * POST /documents/detached) or initiateMultipartUpload() (HTTP:
     * POST /documents/multipart/initiate) — the owner isn't attached to the
     * upload yet at that point. A correct implementation must handle this
     * case explicitly (e.g. a role/permission check independent of any
     * specific model) rather than falling through to an ownership check that
     * can never pass for a null $documentable, which would silently deny
     * every detached/initiate call with no indication why.
     */
    public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $documentable): bool;

    public function canView(?Authenticatable $user, Document $document): bool;

    public function canDelete(?Authenticatable $user, Document $document): bool;
}
