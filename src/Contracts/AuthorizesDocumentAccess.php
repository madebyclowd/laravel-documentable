<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;

/**
 * Decides who may do what with documents over HTTP. Every method receives the user the request
 * authenticated as, or null for an anonymous caller, and the thing being acted on. None of them takes a
 * nullable subject: a question is always about a real type, session, document or owner, so a policy never
 * has to guess what "no owner" means.
 *
 * The package ships `Defaults\GateDocumentAuthorizer`, which asks Laravel's Gate and denies whatever the app
 * has not defined. Bind your own implementation with `documentable.authorization.resolver`
 * (`php artisan documents:make-authorizer` scaffolds either style).
 *
 * `DocumentService` has no request or user of its own and never consults this contract; it guards the HTTP
 * controllers.
 *
 * @api
 */
interface AuthorizesDocumentAccess
{
    /**
     * May the caller start an upload of this type? Asked before any bytes move, so no session or owner exists
     * yet. Also decides which types `GET /documents/types` offers.
     */
    public function canInitiateUpload(?Authenticatable $user, DocumentType $type): bool;

    /**
     * May the caller drive this session? Asked for every follow-up call on a session: part URLs, listing parts,
     * status, abort and finalize. The session carries its document type and the user key it was started for.
     */
    public function canFinalize(?Authenticatable $user, UploadSession $session): bool;

    /**
     * May the caller attach this upload to this owner? `$source` is the `uploaded` session being attached, or,
     * for a direct `POST /documents` upload that has no session yet, an unsaved `Document` that carries only
     * its `document_type_id`.
     */
    public function canAttach(?Authenticatable $user, UploadSession|Document $source, Model $owner): bool;

    public function canView(?Authenticatable $user, Document $document): bool;

    public function canDelete(?Authenticatable $user, Document $document): bool;

    /**
     * The subset of `$documents` the caller may view, in the order given. Asked once per page of a listing, so
     * an implementation can answer with one query instead of one per document. It must never return a document
     * that was not passed in.
     *
     * @param  Collection<int, Document>  $documents
     * @return Collection<int, Document>
     */
    public function canViewMany(?Authenticatable $user, Collection $documents): Collection;
}
