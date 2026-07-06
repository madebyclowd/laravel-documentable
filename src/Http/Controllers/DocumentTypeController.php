<?php

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MadeByClowd\Documentable\Models\DocumentType;

class DocumentTypeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            DocumentType::whereNull('deleted_at')
                ->get(['id', 'code', 'name', 'max_size_mb', 'allowed_mimes', 'allows_multiple', 'requires_versioning'])
        );
    }
}
