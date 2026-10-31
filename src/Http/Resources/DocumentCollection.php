<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Resources;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;

class DocumentCollection extends ResourceCollection
{
    /** @var class-string */
    public $collects = DocumentResource::class;

    /**
     * Sign a temporary URL for every document in the collection. Off by default: a listing should not sign URLs
     * nobody opens.
     */
    public function withUrl(bool $include = true): static
    {
        Collection::wrap($this->collection)->each(fn (DocumentResource $resource) => $resource->withUrl($include));

        return $this;
    }
}
