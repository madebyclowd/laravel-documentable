<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Http\Requests\ListDocumentsRequest;
use MadeByClowd\Documentable\Http\Requests\UploadDocumentRequest;
use MadeByClowd\Documentable\Http\Requests\UrlRequest;
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
     * the query every consumer otherwise hand-rolls.
     *
     * The page is cut in SQL in a stable order (type, group, id), then the authorizer is asked once, through
     * `canViewMany()`, which of that page's documents the caller may see. So `meta.total` and `meta.last_page`
     * count the owner's latest documents, hidden ones included, and a page can hold fewer than `per_page`
     * documents (even none) when some are hidden. The contract has no way to count what a caller may view
     * without loading it, and loading everything to find out is what this method no longer does. The
     * response shape is unchanged.
     */
    public function index(ListDocumentsRequest $request): JsonResponse
    {
        $documentable = $this->resolveDocumentable(
            $request->string('documentable_type')->toString(),
            $request->string('documentable_id')->toString()
        );

        $documentTypeId = $request->input('document_type_id');

        $perPage = max(1, (int) config('documentable.listing.per_page', 50));
        $page = max(1, (int) $request->input('page', 1));

        $paginator = $this->service->pageForOwner($documentable, $documentTypeId, $perPage, $page);
        $total = $paginator->total();

        $visible = $this->authorizer->canViewMany($request->user(), collect($paginator->items()));

        $grouped = $visible
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

    /**
     * With owner fields the file is stored and attached, and the document is returned. Without them the upload
     * is staged: the response is its `uploaded` session, to attach once the owner exists.
     */
    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $type = $this->resolveDocumentType($request->string('document_type_id')->toString());

        if (! $this->authorizer->canInitiateUpload($request->user(), $type)) {
            abort(403);
        }

        if (! $request->filled('documentable_type')) {
            return response()->json($this->service->stageUpload($request->file('file'), $type, $this->sessionUserKey($request)), 201);
        }

        $documentable = $this->resolveDocumentable(
            $request->string('documentable_type')->toString(),
            $request->string('documentable_id')->toString()
        );

        // No session or document exists before the bytes are stored, so the attach question is asked of an unsaved
        // document that carries the type and nothing else.
        $candidate = new Document(['document_type_id' => $type->id]);

        if (! $this->authorizer->canAttach($request->user(), $candidate, $documentable)) {
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

    public function url(UrlRequest $request, Document $document): JsonResponse
    {
        if (! $this->authorizer->canView($request->user(), $document)) {
            abort(403);
        }

        $url = $this->service->getUrl($document, now()->addMinutes($request->expiresInMinutes()), $request->disposition());

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
