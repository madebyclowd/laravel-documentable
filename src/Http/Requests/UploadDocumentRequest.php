<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Upload a file through the server. With an owner the document is created; without one the upload is staged as a session to attach later.
 */
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'document_type_id' => ['required', 'string', Rule::exists('document_types', 'id')->whereNull('deleted_at')],
            'documentable_type' => ['nullable', 'string', 'required_with:documentable_id'],
            'documentable_id' => ['nullable', 'string', 'required_with:documentable_type'],
            'document_group_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
