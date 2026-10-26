<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Http\Requests\AttachUploadRequest;
use MadeByClowd\Documentable\Http\Requests\FinalizeUploadRequest;
use MadeByClowd\Documentable\Http\Requests\PresignUploadRequest;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;

/**
 * Direct/presigned single-PUT path for files under multipart.threshold_bytes, and the attach step every
 * session-based upload ends with. Separate from MultipartUploadController, which handles the
 * initiate/part-url/complete/abort lifecycle.
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

        $started = $this->service->initiatePresignedUpload(
            $type,
            $request->string('filename')->toString(),
            $this->sessionUserKey($request),
            $this->declared($request)
        );

        return response()->json([
            'session_id' => $started['session']->id,
            'url' => $started['url'],
            'headers' => $started['headers'],
            'expires_at' => $started['session']->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Without owner fields the session ends `uploaded` and is returned. With them it is attached in the same
     * call and the document is returned.
     */
    public function finalize(FinalizeUploadRequest $request): JsonResponse
    {
        $session = $this->resolveSession($request, $request->string('session_id')->toString());

        $this->authorizeSession($request, $session);

        $session = $this->service->finalizeDirectUpload($session, $request->input('expected_hash'));

        if (! $request->filled('documentable_type')) {
            return response()->json($session);
        }

        return $this->attachResponse($request, $session);
    }

    public function attach(AttachUploadRequest $request): JsonResponse
    {
        $session = $this->resolveSession($request, $request->string('session_id')->toString());

        $this->authorizeSession($request, $session);

        return $this->attachResponse($request, $session);
    }

    private function attachResponse(Request $request, UploadSession $session): JsonResponse
    {
        $owner = $this->resolveDocumentable(
            $request->string('documentable_type')->toString(),
            $request->string('documentable_id')->toString()
        );

        if (! $this->authorizer->canUpload($request->user(), $session->documentTypeOrFail(), $owner)) {
            abort(403);
        }

        $document = $this->service->attach($session, $owner, new AttachOptions(
            (array) $request->input('metadata', []),
            $request->input('document_group_id')
        ));

        return response()->json($document->load('storageFile'), 201);
    }

    /**
     * Follow-up calls on a session act with the same authority as the call that started it. The owner is not
     * attached yet, hence the null documentable.
     */
    private function authorizeSession(Request $request, UploadSession $session): void
    {
        if (! $this->authorizer->canUpload($request->user(), $session->documentTypeOrFail(), null)) {
            abort(403);
        }
    }

    private function declared(Request $request): UploadSessionData
    {
        return new UploadSessionData(
            $request->filled('declared_size') ? $request->integer('declared_size') : null,
            $request->input('declared_mime'),
            $request->input('declared_hash'),
        );
    }
}
