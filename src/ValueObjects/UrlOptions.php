<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\ValueObjects;

use DateInterval;
use DateTimeInterface;
use InvalidArgumentException;
use MadeByClowd\Documentable\Http\Requests\UrlRequest;

final readonly class UrlOptions
{
    /** @var list<string> */
    public const INLINE_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
        'application/pdf',
    ];

    public function __construct(
        public DateInterval|DateTimeInterface|null $ttl = null,
        public string $disposition = 'inline',
        public ?string $filename = null,
        public ?string $contentType = null,
    ) {
        if (! in_array($disposition, ['inline', 'attachment'], true)) {
            throw new InvalidArgumentException("Disposition must be 'inline' or 'attachment', got '{$disposition}'.");
        }

        if ($disposition === 'inline' && $contentType !== null && ! in_array(strtolower(trim(explode(';', $contentType)[0])), self::INLINE_MIMES, true)) {
            throw new InvalidArgumentException("Content type '{$contentType}' is not permitted for inline disposition.");
        }
    }

    /**
     * When the URL stops working: the requested ttl, or the default, never later than `url.max_ttl_minutes` from now.
     */
    public function expiration(): DateTimeInterface
    {
        $latest = now()->addMinutes(max(1, (int) config('documentable.url.max_ttl_minutes', UrlRequest::DEFAULT_MAX_TTL_MINUTES)));

        $requested = match (true) {
            $this->ttl === null => now()->addMinutes(UrlRequest::DEFAULT_TTL_MINUTES),
            $this->ttl instanceof DateInterval => now()->add($this->ttl),
            default => $this->ttl,
        };

        return $requested > $latest ? $latest : $requested;
    }
}
