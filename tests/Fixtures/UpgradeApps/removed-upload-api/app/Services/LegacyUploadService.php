<?php

declare(strict_types=1);

namespace App\Services;

use MadeByClowd\Documentable\Enums\DocumentStatus;

class LegacyUploadService
{
    public function upload($service, $document, $doc): void
    {
        // $service->uploadDetached('commented');
        // $service->uploadDetached('hash comment');
        /* * $service->uploadDetached('star comment'); */
        $service->uploadDetached('test');
        $service->reassociateDocument('id', 'new-id');
        $document->commit();
        $status = DocumentStatus::Pending;
        $isPending = $doc->status === 'pending';
    }
}
