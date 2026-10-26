<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PartUploadUrlRequest extends FormRequest
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
            'part_number' => ['required', 'integer', 'min:1', 'max:10000'],
            'user_id' => ['nullable', 'string'],
        ];
    }
}
