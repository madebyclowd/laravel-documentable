<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Validation\ValidationException;
use MadeByClowd\Documentable\Models\DocumentType;

/**
 * Business rules every upload transport must pass before it touches storage.
 *
 * @internal
 */
final class UploadGuard
{
    /**
     * Soft-deleted DocumentTypes must not accept new uploads.
     * A FormRequest-level `Rule::exists(...)->whereNull('deleted_at')` check
     * is still recommended at the controller layer, but this is the
     * business rule living where it belongs regardless of caller — service
     * methods called directly (tests, jobs, artisan commands) get the same
     * protection a validated HTTP request would.
     */
    public static function assertTypeActive(DocumentType $type): void
    {
        if ($type->trashed()) {
            throw ValidationException::withMessages([
                'document_type_id' => 'This document type is no longer active.',
            ]);
        }
    }
}
