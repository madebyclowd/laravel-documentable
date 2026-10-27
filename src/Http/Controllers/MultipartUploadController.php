<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Http\Requests\AbortMultipartUploadRequest;
use MadeByClowd\Documentable\Http\Requests\CompleteMultipartUploadRequest;
use MadeByClowd\Documentable\Http\Requests\InitiateMultipartUploadRequest;
use MadeByClowd\Documentable\Http\Requests\ListPartsRequest;
use MadeByClowd\Documentable\Http\Requests\MultipartSessionStatusRequest;
use MadeByClowd\Documentable\Http\Requests\PartUploadUrlRequest;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;

class MultipartUploadController extends Controller
{
    public function __construct(
        protected DocumentService $service,
        protected AuthorizesDocumentAccess $authorizer
    ) {}

    public function initiate(InitiateMultipartUploadRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());

        if (! $this->authorizer->canInitiateUpload($request->user(), $type)) {
            abort(403);
        }

        $userId = $this->resolveUserId($request);

        $session = $this->service->initiateMultipartUpload(
            $type,
            $request->string('filename')->toString(),
            $userId,
            new UploadSessionData(
                $request->filled('declared_size') ? $request->integer('declared_size') : null,
                $request->input('declared_mime'),
                $request->input('declared_hash'),
            )
        );

        return response()->json($session, 201);
    }

    public function partUrl(PartUploadUrlRequest $request): JsonResponse
    {
        $session = $this->ownedSession($request);

        $url = $this->service->generatePartUploadUrl($session, $request->integer('part_number'));

        return response()->json(['url' => $url]);
    }

    public function listParts(ListPartsRequest $request): JsonResponse
    {
        $session = $this->ownedSession($request);

        return response()->json(['parts' => $this->service->listPartsForSession($session)]);
    }

    /**
     * Status check, not a lookup that throws on absence: a session that is gone, reaped or not yours is an
     * expected outcome for a resuming client, so it answers `exists: false` instead of an error.
     */
    public function status(MultipartSessionStatusRequest $request): JsonResponse
    {
        $session = UploadSession::find($request->string('session_id')->toString());

        if ($session === null || $session->user_id !== $this->sessionUserKey($request)) {
            return response()->json(['exists' => false, 'status' => null, 'expires_at' => null, 'disk' => null]);
        }

        $this->authorizeSession($request, $session);

        return response()->json([
            'exists' => true,
            'status' => $session->status->value,
            'expires_at' => $session->expires_at->toIso8601String(),
            'disk' => $session->disk,
        ]);
    }

    /**
     * Without owner fields the session ends `uploaded` and is returned. With them it is attached in the same
     * call and the document is returned.
     */
    public function complete(CompleteMultipartUploadRequest $request): JsonResponse
    {
        $session = $this->ownedSession($request);

        $session = $this->service->completeMultipartUpload($session, $request->input('parts'), $request->input('expected_hash'));

        if (! $request->filled('documentable_type')) {
            return response()->json($session);
        }

        $owner = $this->resolveDocumentable(
            $request->string('documentable_type')->toString(),
            $request->string('documentable_id')->toString()
        );

        if (! $this->authorizer->canAttach($request->user(), $session, $owner)) {
            abort(403);
        }

        $document = $this->service->attach($session, $owner, new AttachOptions(
            (array) $request->input('metadata', []),
            $request->input('document_group_id')
        ));

        return response()->json($document->load('storageFile'), 201);
    }

    public function abort(AbortMultipartUploadRequest $request): Response
    {
        $this->service->abortUploadSession($this->ownedSession($request));

        return response()->noContent();
    }

    private function ownedSession(Request $request): UploadSession
    {
        $session = $this->resolveSession($request, $request->string('session_id')->toString());

        $this->authorizeSession($request, $session);

        return $session;
    }

    /**
     * Follow-up calls on a session are asked of the session itself, the same question for every one of them.
     */
    private function authorizeSession(Request $request, UploadSession $session): void
    {
        if (! $this->authorizer->canFinalize($request->user(), $session)) {
            abort(403);
        }
    }
}
