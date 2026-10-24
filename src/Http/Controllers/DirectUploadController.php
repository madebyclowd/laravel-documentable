<?php

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Http\Requests\FinalizeUploadRequest;
use MadeByClowd\Documentable\Http\Requests\PresignUploadRequest;
use MadeByClowd\Documentable\Services\DocumentService;

/**
 * Direct/presigned single-PUT path for files under multipart.threshold_bytes
 * — separate from MultipartUploadController, which handles the
 * initiate/part-url/complete/abort session lifecycle. Small files skip
 * multipart because its per-part round trips only add overhead below the
 * threshold.
 */
class DirectUploadController extends Controller
{
    public function __construct(
        protected DocumentService $service,
        protected AuthorizesDocumentAccess $authorizer
    ) {}

    public function presign(PresignUploadRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());

        if (! $this->authorizer->canUpload($request->user(), $type, null)) {
            abort(403);
        }

        $presigned = $this->service->createPresignedUpload($type, $request->string('filename')->toString());

        return response()->json($presigned);
    }

    public function finalize(FinalizeUploadRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());
        $documentable = $this->resolveDocumentable(
            $request->string('documentable_type')->toString(),
            $request->string('documentable_id')->toString()
        );

        if (! $this->authorizer->canUpload($request->user(), $type, $documentable)) {
            abort(403);
        }

        $document = $this->service->finalizeDirectUpload(
            $request->string('path')->toString(),
            $type,
            $documentable,
            $request->string('filename')->toString(),
            $request->input('expected_hash'),
            $request->input('metadata', []),
            $request->input('document_group_id')
        );

        return response()->json($document->load('storageFile'), 201);
    }
}
