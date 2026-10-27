<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Models\UploadSession;

abstract class Controller extends BaseController
{
    protected function resolveDocumentable(string $type, string $id): Model
    {
        $class = Relation::getMorphedModel($type);

        if ($class === null) {
            $allowlist = config('documentable.security.allowed_documentable_types');

            if (is_array($allowlist) && in_array($type, $allowlist, true)) {
                $class = $type;
            }
        }

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw ValidationException::withMessages(['documentable_type' => 'Unknown documentable type.']);
        }

        $model = $class::find($id);

        if (! $model) {
            throw ValidationException::withMessages(['documentable_id' => 'Documentable record not found.']);
        }

        return $model;
    }

    /**
     * The upload session a request names, if the caller may drive it. A client holds a session id and nothing
     * else; the path, provider id and user binding all live on the server, so these checks are the whole gate:
     * unknown id is a 404, someone else's session is a 403, and a `document_type_id` that disagrees with the
     * session's own type is a 422.
     */
    protected function resolveSession(Request $request, string $id): UploadSession
    {
        $session = UploadSession::find($id) ?? abort(404);

        if ($session->user_id !== $this->sessionUserKey($request)) {
            abort(403);
        }

        $claimedType = $request->input('document_type_id');

        if (is_string($claimedType) && $claimedType !== '' && $claimedType !== $session->document_type_id) {
            throw ValidationException::withMessages(['document_type_id' => 'This upload session was started for a different document type.']);
        }

        return $session;
    }

    /**
     * The key a session is stored under: the authenticated user, else null. Anonymous callers therefore only
     * ever reach anonymous sessions. Nothing the client sends can name another owner.
     */
    protected function sessionUserKey(Request $request): ?string
    {
        $userId = $request->user()?->getAuthIdentifier();

        return $userId === null ? null : (string) $userId;
    }

    protected function resolveDocumentType(string $id): DocumentType
    {
        return DocumentType::whereNull('deleted_at')->findOrFail($id);
    }

    /**
     * Ownership-scoped operations (multipart sessions) need a stable user identifier. It comes from the
     * authenticated request user, whatever guard the consuming app configured (the package does not hardcode
     * one); a `user_id` in the request is never read.
     */
    protected function resolveUserId(Request $request): string
    {
        $userId = $this->sessionUserKey($request);

        if ($userId === null) {
            throw ValidationException::withMessages([
                'user_id' => 'An authenticated user is required.',
            ]);
        }

        return $userId;
    }
}
