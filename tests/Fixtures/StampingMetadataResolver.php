<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Contracts\ResolvesAttachMetadata;
use MadeByClowd\Documentable\Models\UploadSession;
use MadeByClowd\Documentable\ValueObjects\AttachOptions;

/**
 * Stamps whatever a test puts in `$stamp` and remembers what it was asked about.
 */
final class StampingMetadataResolver implements ResolvesAttachMetadata
{
    /** @var array<string, mixed> */
    public static array $stamp = [];

    public static ?UploadSession $session = null;

    public static ?Model $owner = null;

    public static ?AttachOptions $requested = null;

    public static function reset(): void
    {
        self::$stamp = [];
        self::$session = null;
        self::$owner = null;
        self::$requested = null;
    }

    public function resolve(UploadSession $session, Model $owner, AttachOptions $requested): array
    {
        self::$session = $session;
        self::$owner = $owner;
        self::$requested = $requested;

        return self::$stamp;
    }
}
