<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinalizeUploadRequest extends FormRequest
{
    /** S3-compatible object keys are at most 1,024 bytes long. */
    private const MAX_PATH_LENGTH = 1024;

    /**
     * A relative key: no leading slash, no ".." segment, no backslash, no control characters. The path is
     * a client-supplied claim on this transport, so its shape is checked before it ever reaches storage.
     */
    private const SAFE_PATH_PATTERN = '#^(?!/)(?!.*(?:^|/)\.\.(?:/|$))[^\\\\\x00-\x1F]+$#';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:'.self::MAX_PATH_LENGTH, 'regex:'.self::SAFE_PATH_PATTERN],
            'document_type_id' => ['required', 'string', Rule::exists('document_types', 'id')->whereNull('deleted_at')],
            'documentable_type' => ['required', 'string'],
            'documentable_id' => ['required', 'string'],
            'filename' => ['required', 'string'],
            'expected_hash' => ['nullable', 'string'],
            'document_group_id' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        $shape = 'The path must be a relative storage key without ".." segments, backslashes or control characters.';

        return [
            'path.max' => $shape,
            'path.regex' => $shape,
        ];
    }
}
