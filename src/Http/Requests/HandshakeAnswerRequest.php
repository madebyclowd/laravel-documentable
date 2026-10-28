<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HandshakeAnswerRequest extends FormRequest
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
            'challenge_id' => ['required', 'string', 'uuid'],
            'proof' => ['required', 'string', 'size:64', 'regex:/^[0-9a-fA-F]{64}$/'],
            'filename' => ['nullable', 'string', 'max:255'],
        ];
    }
}
