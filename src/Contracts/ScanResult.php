<?php

namespace MadeByClowd\Documentable\Contracts;

enum ScanResult: string
{
    case Clean = 'clean';
    case Infected = 'infected';
    case Error = 'error';

    public function isInfected(): bool
    {
        return $this === self::Infected;
    }
}
