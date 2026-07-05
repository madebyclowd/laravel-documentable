<?php

namespace MadeByClowd\Documentable\Defaults;

use MadeByClowd\Documentable\Contracts\ScanResult;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;

class NullFileScanner implements ScansUploadedFile
{
    public function scan(string $disk, string $path): ScanResult
    {
        return ScanResult::Clean;
    }
}
