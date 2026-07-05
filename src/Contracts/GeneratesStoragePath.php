<?php

namespace MadeByClowd\Documentable\Contracts;

use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Controls the storage key a newly-stored file is written to. Default
 * (MadeByClowd\Documentable\Defaults\DefaultStoragePathGenerator) returns
 * "{$type->path_prefix}/{uuid}". Return value is a full relative path
 * (prefix included, if any) — bind your own to shard by date, tenant, etc.
 */
interface GeneratesStoragePath
{
    public function generate(DocumentType $type, string $filename): string;
}
