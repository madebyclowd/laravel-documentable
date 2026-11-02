<?php

declare(strict_types=1);

namespace App\Services;

use MadeByClowd\Documentable\Services\DocumentService;

class CustomDocumentService extends DocumentService
{
    public function customAction(): void
    {
        $repo = $this->storageFileRepository;
    }
}
