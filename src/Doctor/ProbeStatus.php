<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

/**
 * The status verdict of a doctor probe.
 *
 * @internal
 */
enum ProbeStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skipped = 'skipped';
}
