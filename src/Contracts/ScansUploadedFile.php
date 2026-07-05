<?php

namespace MadeByClowd\Documentable\Contracts;

/**
 * Hook for AV/malware scanning of newly-stored bytes. Default
 * (MadeByClowd\Documentable\Defaults\NullFileScanner) is a no-op returning
 * Clean — scanning itself is infrastructure (e.g. a ClamAV sidecar or S3
 * Object Lambda), out of scope for the package to implement, but the seam
 * must exist so a consumer can wire one in without forking.
 */
interface ScansUploadedFile
{
    public function scan(string $disk, string $path): ScanResult;
}
