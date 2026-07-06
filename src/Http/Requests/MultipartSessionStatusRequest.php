<?php

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MultipartSessionStatusRequest extends FormRequest
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
            'user_id' => ['nullable', 'string'],
        ];
    }
}
