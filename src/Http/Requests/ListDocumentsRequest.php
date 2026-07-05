<?php

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * This is an owner-scoped listing, not a global one — documentable_type/id
     * are required together. document_type_id narrows to one type when given,
     * scoped to non-soft-deleted rows same as every other document_type_id rule
     * in this package (bugs.md #4).
     */
    public function rules(): array
    {
        return [
            'documentable_type' => ['required', 'string'],
            'documentable_id' => ['required', 'string'],
            'document_type_id' => ['nullable', 'string', Rule::exists('document_types', 'id')->whereNull('deleted_at')],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
