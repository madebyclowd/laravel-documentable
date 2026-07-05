<?php

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Http\Requests\ListDocumentsRequest;
use MadeByClowd\Documentable\Http\Requests\UploadDetachedDocumentRequest;
use MadeByClowd\Documentable\Http\Requests\UploadDocumentRequest;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Services\DocumentService;

class DocumentController extends Controller
{
    public function __construct(
        protected DocumentService $service,
        protected AuthorizesDocumentAccess $authorizer
    ) {}

    /**
     * Owner-scoped listing, grouped by document_type_id then document_group_id —
     * the query docs/feedbacks/feedback.md #6 says every consumer was hand-rolling.
     * canView() is per-Document, so it's applied as a post-query collection filter,
     * not a WHERE clause (see phase-12 doc for the trade-off) — pagination
     * therefore happens on the filtered collection, not at the database level.
     */
    public function index(ListDocumentsRequest $request): JsonResponse
    {
        $documentable = $this->resolveDocumentable(
            $request->string('documentable_type')->toString(),
            $request->string('documentable_id')->toString()
        );

        $documentTypeId = $request->input('document_type_id');

        $documents = $this->service->listForOwner($documentable, $documentTypeId)
            ->filter(fn (Document $document) => $this->authorizer->canView($request->user(), $document))
            ->values();

        $perPage = max(1, (int) config('documentable.listing.per_page', 50));
        $page = max(1, (int) $request->input('page', 1));
        $total = $documents->count();

        $paged = $documents->forPage($page, $perPage)->values();

        $grouped = $paged
            ->groupBy('document_type_id')
            ->map(fn ($group) => $group->groupBy('document_group_id')->map(fn ($versions) => $versions->first()));

        return response()->json([
            'data' => $grouped,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }

    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());
        $documentable = $this->resolveDocumentable(
            $request->string('documentable_type')->toString(),
            $request->string('documentable_id')->toString()
        );

        if (! $this->authorizer->canUpload($request->user(), $type, $documentable)) {
            abort(403);
        }

        $document = $this->service->upload(
            $request->file('file'),
            $type,
            $documentable,
            $request->input('metadata', []),
            $request->input('document_group_id')
        );

        return response()->json($document->load('storageFile'), 201);
    }

    public function storeDetached(UploadDetachedDocumentRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());

        if (! $this->authorizer->canUpload($request->user(), $type, null)) {
            abort(403);
        }

        $document = $this->service->uploadDetached(
            $request->file('file'),
            $type,
            $request->input('metadata', []),
            $request->boolean('pending'),
            $request->integer('ttl_hours') ?: null
        );

        return response()->json($document->load('storageFile'), 201);
    }

    public function url(Request $request, Document $document): JsonResponse
    {
        if (! $this->authorizer->canView($request->user(), $document)) {
            abort(403);
        }

        $disposition = $request->input('disposition', 'inline');
        $expiresInMinutes = (int) $request->input('expires_in_minutes', 5);

        $url = $this->service->getUrl($document, now()->addMinutes($expiresInMinutes), $disposition);

        return response()->json(['url' => $url]);
    }

    public function destroy(Request $request, Document $document): Response
    {
        if (! $this->authorizer->canDelete($request->user(), $document)) {
            abort(403);
        }

        $this->service->delete($document);

        return response()->noContent();
    }
}
