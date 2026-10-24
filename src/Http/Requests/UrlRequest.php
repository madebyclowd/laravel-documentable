<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UrlRequest extends FormRequest
{
    public const DEFAULT_TTL_MINUTES = 5;

    public const DEFAULT_MAX_TTL_MINUTES = 1440;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `disposition` ends up inside a response header parameter and `expires_in_minutes` decides how long a
     * bearer URL lives, so neither is taken as given: the disposition is an allow-list and the TTL is
     * bounded by `url.max_ttl_minutes`.
     */
    public function rules(): array
    {
        $maxTtl = max(1, (int) config('documentable.url.max_ttl_minutes', self::DEFAULT_MAX_TTL_MINUTES));

        return [
            'disposition' => ['nullable', 'string', Rule::in(['inline', 'attachment'])],
            'expires_in_minutes' => ['nullable', 'integer', 'between:1,'.$maxTtl],
        ];
    }

    public function disposition(): string
    {
        return $this->string('disposition', 'inline')->toString();
    }

    public function expiresInMinutes(): int
    {
        return $this->integer('expires_in_minutes', self::DEFAULT_TTL_MINUTES);
    }
}
