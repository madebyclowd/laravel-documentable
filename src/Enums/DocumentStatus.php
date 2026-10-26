<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Enums;

/**
 * @api
 */
enum DocumentStatus: string
{
    /** Verified and usable. */
    case Committed = 'committed';

    /** The object is stored but still being verified in the background. */
    case Processing = 'processing';

    /** Verification refused the object; it is kept for inspection and never served. */
    case Rejected = 'rejected';
}
