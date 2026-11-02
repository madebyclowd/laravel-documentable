<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use MadeByClowd\Documentable\Models\Document;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    protected bool $includeUrl = false;

    /**
     * Opt-in to computing and including a temporary presigned URL on the resource.
     */
    public function withUrl(bool $include = true): static
    {
        $this->includeUrl = $include;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'filename' => $this->filename,
            'size' => $this->size,
            'mime' => $this->mime,
            'version' => $this->version,
            'type' => $this->documentTypeOrFail()->code,
        ];

        if ($this->includeUrl) {
            $data['url'] = $this->temporaryUrl();
        }

        return $data;
    }

    /**
     * @param  mixed  $resource
     */
    public static function collection($resource): DocumentCollection
    {
        return new DocumentCollection($resource);
    }
}
