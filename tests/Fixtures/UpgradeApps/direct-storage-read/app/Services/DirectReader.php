<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Models\Document;

class DirectReader
{
    public function read(Document $document): string
    {
        return Storage::disk('s3')->get($document->storageFile->path);
    }
}
