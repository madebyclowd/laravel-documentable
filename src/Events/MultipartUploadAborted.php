<?php

namespace MadeByClowd\Documentable\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use MadeByClowd\Documentable\Models\MultipartUpload;

class MultipartUploadAborted implements ShouldDispatchAfterCommit
{
    public function __construct(public MultipartUpload $session) {}
}
