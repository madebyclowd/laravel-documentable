<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Defaults\PermissiveDocumentAuthorizer;
use MadeByClowd\Documentable\Models\DocumentType;

class LegacyAuthorizer extends PermissiveDocumentAuthorizer
{
    public function canUpload(?Authenticatable $user, DocumentType $type, ?Model $documentable): bool
    {
        return true;
    }
}
