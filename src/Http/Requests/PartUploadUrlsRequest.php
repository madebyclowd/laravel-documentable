<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use MadeByClowd\Documentable\Services\PartSizePlanner;

/**
 * A batch of part numbers to sign in one request. Signing is a local computation, so the client asks for as many
 * as it is about to upload instead of paying one request, one rate-limit hit and one session load per part.
 */
class PartUploadUrlsRequest extends FormRequest
{
    /** The most parts one request may ask for. */
    public const MAX_PARTS_PER_REQUEST = 100;

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
            'part_numbers' => ['required', 'array', 'min:1', 'max:'.self::MAX_PARTS_PER_REQUEST],
            'part_numbers.*' => ['required', 'integer', 'min:1', 'max:'.PartSizePlanner::MAX_PARTS, 'distinct'],
            'user_id' => ['nullable', 'string'],
        ];
    }
}
