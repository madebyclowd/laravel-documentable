<?php

namespace MadeByClowd\Documentable\Events;

use MadeByClowd\Documentable\Models\MultipartUpload;

class MultipartUploadInitiated
{
    public function __construct(public MultipartUpload $session) {}
}
