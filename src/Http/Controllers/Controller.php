<?php

namespace MadeByClowd\Documentable\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\DocumentType;

abstract class Controller extends BaseController
{
    protected function resolveDocumentable(string $type, string $id): Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw ValidationException::withMessages(['documentable_type' => 'Unknown documentable type.']);
        }

        $model = $class::find($id);

        if (! $model) {
            throw ValidationException::withMessages(['documentable_id' => 'Documentable record not found.']);
        }

        return $model;
    }

    protected function resolveDocumentType(string $id): DocumentType
    {
        return DocumentType::whereNull('deleted_at')->findOrFail($id);
    }

    /**
     * Ownership-scoped operations (multipart sessions) need a stable user
     * identifier. Prefer the authenticated request user (whatever guard the
     * consuming app configured — package doesn't hardcode one); fall back to
     * an explicit user_id field for guest/device-scoped upload flows.
     */
    protected function resolveUserId(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier() ?? $request->input('user_id');

        if ($userId === null) {
            throw ValidationException::withMessages([
                'user_id' => 'An authenticated user or an explicit user_id is required.',
            ]);
        }

        return (string) $userId;
    }
}
