<?php

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PresignUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'string', Rule::exists('document_types', 'id')->whereNull('deleted_at')],
            'filename' => ['required', 'string'],
        ];
    }
}
