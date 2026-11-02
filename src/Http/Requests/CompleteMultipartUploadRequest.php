<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Complete a multipart session. The owner is optional: without one the session stays `uploaded` for a later attach.
 */
class CompleteMultipartUploadRequest extends FormRequest
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
            'session_id' => ['required', 'string', 'size:26'],
            'document_type_id' => ['nullable', 'string'],
            'documentable_type' => ['nullable', 'string', 'required_with:documentable_id'],
            'documentable_id' => ['nullable', 'string', 'required_with:documentable_type'],
            'user_id' => ['nullable', 'string'],
            // Only consulted when etag_strategy = 'client'; ignored otherwise.
            'parts' => ['nullable', 'array'],
            'parts.*.PartNumber' => ['required_with:parts', 'integer'],
            'parts.*.ETag' => ['required_with:parts', 'string'],
            'expected_hash' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'document_group_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
