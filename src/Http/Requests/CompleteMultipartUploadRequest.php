<?php

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompleteMultipartUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string'],
            'upload_id' => ['required', 'string'],
            'document_type_id' => ['required', 'string', Rule::exists('document_types', 'id')->whereNull('deleted_at')],
            'documentable_type' => ['required', 'string'],
            'documentable_id' => ['required', 'string'],
            'filename' => ['required', 'string'],
            'user_id' => ['nullable', 'string'],
            // Only consulted when etag_strategy = 'client'; ignored otherwise.
            'parts' => ['nullable', 'array'],
            'parts.*.PartNumber' => ['required_with:parts', 'integer'],
            'parts.*.ETag' => ['required_with:parts', 'string'],
            'expected_hash' => ['nullable', 'string'],
            'document_group_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
