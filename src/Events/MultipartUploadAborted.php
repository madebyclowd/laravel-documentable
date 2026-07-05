<?php

namespace MadeByClowd\Documentable\Events;

use MadeByClowd\Documentable\Models\MultipartUpload;

class MultipartUploadAborted
{
    public function __construct(public MultipartUpload $session) {}
}
