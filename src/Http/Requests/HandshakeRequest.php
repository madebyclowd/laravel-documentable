<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandshakeRequest extends FormRequest
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
            'type' => ['required_without:document_type_id', 'string'],
            'document_type_id' => ['required_without:type', 'string'],
            'hash' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
            'size' => ['required', 'integer', 'min:0'],
            'filename' => ['nullable', 'string', 'max:255'],
            'documentable_type' => ['nullable', 'string', 'required_with:documentable_id'],
            'documentable_id' => ['nullable', 'string', 'required_with:documentable_type'],
        ];
    }
}
