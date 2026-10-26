<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\ValueObjects;

/**
 * Optional choices when attaching an uploaded session to its owner.
 *
 * @api
 */
final readonly class AttachOptions
{
    /**
     * @param  array<string, mixed>  $metadata  stored on the document next to the size and mime type
     * @param  string|null  $documentGroupId  attach as a new version of this existing group; it must already belong to the same owner and type
     */
    public function __construct(
        public array $metadata = [],
        public ?string $documentGroupId = null,
    ) {}
}
