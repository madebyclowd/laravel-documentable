<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start a presigned single PUT. The client names a file and a type; the server picks the path.
 */
class PresignUploadRequest extends FormRequest
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
            'document_type_id' => ['required', 'string', Rule::exists('document_types', 'id')->whereNull('deleted_at')],
            'filename' => ['required', 'string'],
            'declared_size' => ['nullable', 'integer', 'min:0'],
            'declared_mime' => ['nullable', 'string', 'max:255'],
            'declared_hash' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'user_id' => ['nullable', 'string'],
        ];
    }
}
