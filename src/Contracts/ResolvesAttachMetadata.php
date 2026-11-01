<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Contracts;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;

/**
 * Lets the server stamp metadata on a document as it is attached, for keys the client must not be trusted with
 * (a flag that drives a purge job, an approval state). Set it as `attach.metadata_resolver`.
 *
 * It runs inside `attach()`, so every transport goes through it: a direct upload, a presigned PUT, a multipart
 * upload and a staged upload attached later. What it returns is merged over the client's `metadata`, and the
 * keys the package measures itself (`size`, `mime_type`, `original_filename`) are merged over that, so neither
 * can be overwritten.
 *
 * It only adds keys. To refuse an attach, deny it in `AuthorizesDocumentAccess::canAttach()`.
 *
 * @api
 */
interface ResolvesAttachMetadata
{
    /**
     * @param  AttachOptions  $requested  what the caller asked for; its metadata is the client's
     * @return array<string, mixed> the keys to stamp
     */
    public function resolve(UploadSession $session, Model $owner, AttachOptions $requested): array;
}
