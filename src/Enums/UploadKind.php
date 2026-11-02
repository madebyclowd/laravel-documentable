<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Enums;

/**
 * How the bytes of an upload session reach storage.
 *
 * @api
 */
enum UploadKind: string
{
    /** One request: a server-proxied upload or a presigned single PUT. */
    case Single = 'single';

    /** A provider multipart session assembled from parts. */
    case Multipart = 'multipart';
}
