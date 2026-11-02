<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Enums;

/**
 * @api
 */
enum OnDeleteLatest: string
{
    case Promote = 'promote';
    case None = 'none';
    case Block = 'block';
}
