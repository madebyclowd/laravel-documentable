<?php

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinalizeUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string'],
            'document_type_id' => ['required', 'string', Rule::exists('document_types', 'id')->whereNull('deleted_at')],
            'documentable_type' => ['required', 'string'],
            'documentable_id' => ['required', 'string'],
            'filename' => ['required', 'string'],
            'expected_hash' => ['nullable', 'string'],
            'document_group_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
