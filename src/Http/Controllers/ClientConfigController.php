<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\PartSizePlanner;

/**
 * What a browser uploader needs to know before it starts: the numbers the server already decides, and the types
 * the caller may upload to. A client that reads these never hard-codes them, and never goes stale when the server
 * changes them.
 */
class ClientConfigController extends Controller
{
    public function __construct(
        protected DocumentableConfig $config,
        protected AuthorizesDocumentAccess $authorizer,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $types = DocumentType::whereNull('deleted_at')
            ->get(['id', 'code', 'name', 'max_size_mb', 'allowed_mimes', 'allowed_extensions', 'trust_extension_for_generic_mime', 'allows_multiple'])
            ->filter(fn (DocumentType $type) => $this->authorizer->canInitiateUpload($request->user(), $type))
            ->map(fn (DocumentType $type) => [
                'id' => $type->id,
                'code' => $type->code,
                'name' => $type->name,
                'max_bytes' => $type->max_size_mb * 1024 * 1024,
                'allowed_mimes' => $type->allowed_mimes ?? [],
                'allowed_extensions' => $type->allowed_extensions ?? [],
                'trust_extension_for_generic_mime' => $type->trust_extension_for_generic_mime,
                'allows_multiple' => $type->allows_multiple,
            ])
            ->values();

        return response()->json([
            'threshold_bytes' => $this->config->multipartThresholdBytes,
            'min_part_size' => PartSizePlanner::MIN_PART_SIZE,
            'max_parts' => PartSizePlanner::MAX_PARTS,
            'max_concurrency' => $this->config->clientMaxConcurrency,
            'retry' => $this->config->clientRetry,
            'sync_hash_max_bytes' => $this->config->syncHashMaxBytes,
            'etag_strategy' => $this->config->etagStrategy,
            'types' => $types,
        ])->header('Cache-Control', 'private, no-store');
    }
}
