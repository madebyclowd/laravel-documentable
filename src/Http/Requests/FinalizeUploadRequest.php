<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Finalize a presigned upload by session id. The path is never part of the request: it lives on the session.
 */
class FinalizeUploadRequest extends FormRequest
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
            'expected_hash' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'document_group_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
