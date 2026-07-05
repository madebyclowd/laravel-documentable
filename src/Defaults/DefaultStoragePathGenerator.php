<?php

namespace MadeByClowd\Documentable\Defaults;

use Illuminate\Support\Str;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Models\DocumentType;

class DefaultStoragePathGenerator implements GeneratesStoragePath
{
    public function generate(DocumentType $type, string $filename): string
    {
        return $type->path_prefix.'/'.Str::uuid();
    }
}
