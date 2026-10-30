<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Doctor;

/**
 * Interface implemented by each doctor probe.
 *
 * @internal
 */
interface Probe
{
    public function name(): string;

    public function run(DoctorContext $context): ProbeResult;
}
