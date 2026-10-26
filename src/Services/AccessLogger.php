<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentAccessLog;

/**
 * Best-effort access log, gated by `documentable.audit.access_log` and off by default. The package cannot
 * observe a request to a presigned URL directly, so URL-generation time is the closest proxy for "access".
 *
 * @internal
 */
final class AccessLogger
{
    public function __construct(
        private readonly ActorResolver $actor,
        private readonly Config $config,
        private readonly Container $container,
    ) {}

    public function log(Document $document, string $action): void
    {
        if (! $this->config->get('documentable.audit.access_log', false)) {
            return;
        }

        DocumentAccessLog::create([
            'document_id' => $document->id,
            'actor_id' => $this->actor->id(),
            'action' => $action,
            'ip_address' => $this->container->bound('request') ? $this->container->make('request')->ip() : null,
            'created_at' => now(),
        ]);
    }
}
