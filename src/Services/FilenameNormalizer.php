<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

/**
 * Turns the filename a client sent into one that is safe to store and to show: no path, no control characters,
 * valid UTF-8, a bounded length. The result is what `Document::$client_filename` holds; the original is kept in
 * the document's metadata when it differs.
 *
 * Idempotent: normalizing a normalized name returns it unchanged.
 *
 * @internal
 */
final class FilenameNormalizer
{
    /** The longest name most filesystems and archive formats accept, in bytes. */
    private const MAX_BYTES = 255;

    /** Used when nothing of the name survives. */
    private const FALLBACK = 'file';

    /** Beyond this an "extension" is part of a very long name, not a type suffix to preserve. */
    private const MAX_EXTENSION_BYTES = 32;

    public static function normalize(string $filename): string
    {
        $name = self::withoutInvalidUtf8($filename);
        $name = self::lastSegment($name);
        $name = (string) preg_replace('/\p{Cc}/u', '', $name);
        $name = (string) preg_replace('/ {2,}/', ' ', $name);
        $name = trim($name, '. ');
        $name = self::capped($name);

        return $name === '' ? self::FALLBACK : $name;
    }

    /**
     * Drops every byte that is not part of a well-formed UTF-8 sequence, keeping the rest.
     */
    private static function withoutInvalidUtf8(string $value): string
    {
        return (string) preg_replace(
            '/(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})(*SKIP)(*FAIL)|./s',
            '',
            $value
        );
    }

    /**
     * Browsers once sent the full client path, and either separator can arrive from any client.
     */
    private static function lastSegment(string $name): string
    {
        $segments = explode('/', str_replace('\\', '/', $name));

        return end($segments);
    }

    private static function capped(string $name): string
    {
        if (strlen($name) <= self::MAX_BYTES) {
            return $name;
        }

        $dot = strrpos($name, '.');
        $extension = $dot === false ? '' : substr($name, $dot);

        if ($extension === '' || strlen($extension) > self::MAX_EXTENSION_BYTES) {
            return rtrim(mb_strcut($name, 0, self::MAX_BYTES), '. ');
        }

        return rtrim(mb_strcut(substr($name, 0, $dot), 0, self::MAX_BYTES - strlen($extension)), '. ').$extension;
    }
}
