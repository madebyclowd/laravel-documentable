<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Integrity;

/**
 * @internal
 */
enum RepairAction: string
{
    /** Leaves a row as it is; listed so a plan shows what survives next to what changes. */
    case Keep = 'keep';

    /** Clears `is_latest` and `latest_marker` on a document that lost its slot. */
    case DemoteDocument = 'demote_document';

    /** Points every document and upload session at the kept stored file instead of a duplicate. */
    case RepointStorageFile = 'repoint_storage_file';

    /** Deletes a duplicate stored file row, and its blob only when the kept row does not share it. */
    case RemoveStorageFile = 'remove_storage_file';
}
