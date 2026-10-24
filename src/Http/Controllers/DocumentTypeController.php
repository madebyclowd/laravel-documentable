<?php

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Models\DocumentType;

class DocumentTypeController extends Controller
{
    public function __construct(protected AuthorizesDocumentAccess $authorizer) {}

    /**
     * Lists the types the caller may upload to. Whether a type is offered is the same question as whether an
     * upload of that type would be accepted, so it goes through `canUpload()` with no owner attached.
     */
    public function index(Request $request): JsonResponse
    {
        $types = DocumentType::whereNull('deleted_at')
            ->get(['id', 'code', 'name', 'max_size_mb', 'allowed_mimes', 'allows_multiple', 'requires_versioning'])
            ->filter(fn (DocumentType $type) => $this->authorizer->canUpload($request->user(), $type, null))
            ->values();

        return response()->json($types);
    }
}
