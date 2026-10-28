<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Contracts;

/**
 * Implemented by owner models to identify their tenant partition for deduplication scoping.
 */
interface HasDedupTenant
{
    /**
     * The tenant identifier used to scope file deduplication.
     */
    public function dedupTenantId(): ?string;
}
