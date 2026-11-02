<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Contracts;

use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

interface ModifiesUrlOptions
{
    public function modify(UrlOptions $options, Document $document): UrlOptions;
}
