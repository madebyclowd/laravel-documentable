<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures;

use MadeByClowd\Documentable\Contracts\ModifiesUrlOptions;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

final class ForceAttachmentUrlModifier implements ModifiesUrlOptions
{
    public function modify(UrlOptions $options, Document $document): UrlOptions
    {
        return new UrlOptions(ttl: $options->ttl, disposition: 'attachment', filename: 'modified-'.$document->client_filename);
    }
}
