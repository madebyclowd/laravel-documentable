<?php

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Http\Requests\AbortMultipartUploadRequest;
use MadeByClowd\Documentable\Http\Requests\CompleteMultipartUploadRequest;
use MadeByClowd\Documentable\Http\Requests\InitiateMultipartUploadRequest;
use MadeByClowd\Documentable\Http\Requests\ListPartsRequest;
use MadeByClowd\Documentable\Http\Requests\MultipartSessionStatusRequest;
use MadeByClowd\Documentable\Http\Requests\PartUploadUrlRequest;
use MadeByClowd\Documentable\Services\DocumentService;

class MultipartUploadController extends Controller
{
    public function __construct(
        protected DocumentService $service,
        protected AuthorizesDocumentAccess $authorizer
    ) {}

    public function initiate(InitiateMultipartUploadRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());

        if (! $this->authorizer->canUpload($request->user(), $type, null)) {
            abort(403);
        }

        $userId = $this->resolveUserId($request);

        $session = $this->service->initiateMultipartUpload($request->string('filename')->toString(), $type, $userId);

        return response()->json($session, 201);
    }

    public function partUrl(PartUploadUrlRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());
        $userId = $this->resolveUserId($request);

        $url = $this->service->generatePartUploadUrl(
            $request->string('path')->toString(),
            $request->string('upload_id')->toString(),
            $userId,
            $request->integer('part_number'),
            $type
        );

        return response()->json(['url' => $url]);
    }

    public function complete(CompleteMultipartUploadRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());
        $documentable = $this->resolveDocumentable(
            $request->string('documentable_type')->toString(),
            $request->string('documentable_id')->toString()
        );
        $userId = $this->resolveUserId($request);

        if (! $this->authorizer->canUpload($request->user(), $type, $documentable)) {
            abort(403);
        }

        $document = $this->service->completeMultipartUpload(
            $request->string('path')->toString(),
            $request->string('upload_id')->toString(),
            $userId,
            $type,
            $documentable,
            $request->string('filename')->toString(),
            $request->input('parts'),
            $request->input('expected_hash'),
            $request->input('metadata', []),
            $request->input('document_group_id')
        );

        return response()->json($document->load('storageFile'), 201);
    }

    public function listParts(ListPartsRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());
        $userId = $this->resolveUserId($request);

        $parts = $this->service->listPartsForSession(
            $request->string('path')->toString(),
            $request->string('upload_id')->toString(),
            $userId,
            $type
        );

        return response()->json(['parts' => $parts]);
    }

    public function status(MultipartSessionStatusRequest $request): JsonResponse
    {
        $userId = $this->resolveUserId($request);

        $status = $this->service->multipartSessionStatus(
            $request->string('path')->toString(),
            $request->string('upload_id')->toString(),
            $userId
        );

        return response()->json($status);
    }

    public function abort(AbortMultipartUploadRequest $request): Response
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());
        $userId = $this->resolveUserId($request);

        $this->service->abortMultipartUpload(
            $request->string('path')->toString(),
            $request->string('upload_id')->toString(),
            $userId,
            $type
        );

        return response()->noContent();
    }
}
