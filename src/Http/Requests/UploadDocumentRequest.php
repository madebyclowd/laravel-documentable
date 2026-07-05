<?php

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * document_type_id is scoped to non-soft-deleted rows — defense in depth
     * alongside DocumentService::assertTypeActive() (bugs.md #4): a request
     * naming a deactivated type is rejected before it reaches the service.
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'document_type_id' => ['required', 'string', Rule::exists('document_types', 'id')->whereNull('deleted_at')],
            'documentable_type' => ['required', 'string'],
            'documentable_id' => ['required', 'string'],
            'document_group_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
