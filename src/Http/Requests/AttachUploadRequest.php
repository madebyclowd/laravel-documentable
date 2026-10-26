<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Attach an uploaded session to its owner.
 */
class AttachUploadRequest extends FormRequest
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
            'documentable_type' => ['required', 'string'],
            'documentable_id' => ['required', 'string'],
            'user_id' => ['nullable', 'string'],
            'document_group_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
