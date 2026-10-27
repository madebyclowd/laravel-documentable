<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Config;

use Illuminate\Console\Scheduling\Event;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Drivers\S3MultipartDriver;
use MadeByClowd\Documentable\Exceptions\InvalidDocumentableConfig;
use ReflectionMethod;

/**
 * The validated, immutable view of `config/documentable.php`. Built once at boot from the merged config,
 * so a malformed value throws {@see InvalidDocumentableConfig} then rather than deep inside a request.
 *
 * @api
 */
final readonly class DocumentableConfig
{
    /** @var list<string> */
    public const KEY_TYPES = ['int', 'uuid', 'ulid', 'string'];

    private const ETAG_STRATEGIES = ['client', 'server-authoritative'];

    private const SCAN_ERROR_POLICIES = ['reject', 'allow'];

    /**
     * @param  list<string>  $middleware
     * @param  array<string, mixed>  $types
     * @param  array<string, array<string, mixed>>  $disks
     * @param  array<string, class-string<MultipartUploadDriver>>  $multipartDrivers
     * @param  list<string>|null  $allowedDocumentableTypes
     * @param  class-string<AuthorizesDocumentAccess>|null  $authorizerResolver
     * @param  class-string<ResolvesDedupScope>|null  $dedupScopeResolver
     * @param  class-string<ScansUploadedFile>|null  $scanner
     * @param  class-string<GeneratesStoragePath>|null  $storagePathGenerator
     */
    private function __construct(
        public string $disk,
        public string $keyType,
        public bool $loadMigrations,
        public bool $loadRoutes,
        public array $types,
        public int $multipartThresholdBytes,
        public string $etagStrategy,
        public string $partUploadUrlTtl,
        public int $sessionTtlHours,
        public bool $useNativeChecksum,
        public array $multipartDrivers,
        public string $reaperFrequency,
        public int $storageFileGraceHours,
        public ?string $authorizerResolver,
        public ?string $dedupScopeResolver,
        public ?string $scanner,
        public ?array $allowedDocumentableTypes,
        public bool $allowGuestUserId,
        public string $scanErrorPolicy,
        public int $urlMaxTtlMinutes,
        public ?string $storagePathGenerator,
        public array $disks,
        public array $middleware,
        public int $listingPerPage,
        public string $throttle,
        public bool $auditEnabled,
        public bool $auditAccessLog,
    ) {}

    /**
     * @param  array<string, mixed>  $config  the `documentable` config array
     * @param  list<string>  $knownDisks  names under `filesystems.disks`
     *
     * @throws InvalidDocumentableConfig
     */
    public static function fromArray(array $config, array $knownDisks): self
    {
        $c = self::withDefaults($config);

        $disk = self::string($c['disk'], 'disk', 'Set it to the name of a disk in config/filesystems.php');
        self::requireDisk($disk, 'disk', $knownDisks);

        $keyType = $c['key_type'];
        if (! is_string($keyType) || ! in_array($keyType, self::KEY_TYPES, true)) {
            throw new InvalidDocumentableConfig('key_type', 'must be one of '.implode(', ', self::KEY_TYPES), "Use 'string' unless every document owner shares one key type, then match it: int, uuid or ulid");
        }

        $types = self::arrayValue($c['types'], 'types');
        foreach ($types as $code => $definition) {
            if (is_array($definition) && isset($definition['disk']) && is_string($definition['disk'])) {
                self::requireDisk($definition['disk'], "types.{$code}.disk", $knownDisks);
            }
        }

        $multipart = $c['multipart'];
        $drivers = self::arrayValue($multipart['drivers'], 'multipart.drivers');
        foreach ($drivers as $name => $class) {
            $drivers[$name] = self::implementing($class, MultipartUploadDriver::class, "multipart.drivers.{$name}")
                ?? throw new InvalidDocumentableConfig("multipart.drivers.{$name}", 'must be a class name', 'Bind a class that implements '.MultipartUploadDriver::class);
        }

        $etag = $multipart['etag_strategy'];
        if (! in_array($etag, self::ETAG_STRATEGIES, true)) {
            throw new InvalidDocumentableConfig('multipart.etag_strategy', 'must be one of '.implode(', ', self::ETAG_STRATEGIES), "Use 'server-authoritative' unless your bucket CORS exposes the ETag header");
        }

        $partTtl = $multipart['part_upload_url_ttl'];
        if (! is_string($partTtl) || strtotime($partTtl) === false) {
            throw new InvalidDocumentableConfig('multipart.part_upload_url_ttl', 'must be a relative time string PHP can parse', "Use a value such as '+1 hour'");
        }

        $scanPolicy = $c['security']['scan_error_policy'];
        if (! in_array($scanPolicy, self::SCAN_ERROR_POLICIES, true)) {
            throw new InvalidDocumentableConfig('security.scan_error_policy', 'must be one of '.implode(', ', self::SCAN_ERROR_POLICIES), "Use 'reject' so a scanner outage cannot silently turn scanning off");
        }

        $allowed = $c['security']['allowed_documentable_types'];
        if ($allowed !== null && ! (is_array($allowed) && array_is_list($allowed) && self::allStrings($allowed))) {
            throw new InvalidDocumentableConfig('security.allowed_documentable_types', 'must be null or an array of class names', 'Use null to reject every unmapped type, or list the model classes you accept');
        }

        $middleware = $c['middleware'];
        if (! is_array($middleware)) {
            throw new InvalidDocumentableConfig('middleware', 'must be an array', "Use a list such as ['api']");
        }
        if (! array_is_list($middleware) || ! self::allStrings($middleware)) {
            throw new InvalidDocumentableConfig('middleware', 'must be a list of strings', "Use a list of middleware names such as ['api', 'auth:sanctum']");
        }

        $perPage = self::int($c['listing']['per_page'], 'listing.per_page', 1, 'between 1 and 1000');
        if ($perPage > 1000) {
            throw new InvalidDocumentableConfig('listing.per_page', 'must be between 1 and 1000', 'Page smaller; a caller needing more can ask for the next page');
        }

        return new self(
            disk: $disk,
            keyType: $keyType,
            loadMigrations: self::bool($c['load_migrations'], 'load_migrations'),
            loadRoutes: self::bool($c['load_routes'], 'load_routes'),
            types: $types,
            multipartThresholdBytes: self::int($multipart['threshold_bytes'], 'multipart.threshold_bytes', 1, 'a positive number of bytes'),
            etagStrategy: $etag,
            partUploadUrlTtl: $partTtl,
            sessionTtlHours: self::int($multipart['session_ttl_hours'], 'multipart.session_ttl_hours', 1, 'a positive number of hours'),
            useNativeChecksum: self::bool($multipart['use_native_checksum'], 'multipart.use_native_checksum'),
            multipartDrivers: $drivers,
            reaperFrequency: self::frequency($c['lifecycle']['reaper_frequency']),
            storageFileGraceHours: self::int($c['lifecycle']['storage_file_grace_hours'], 'lifecycle.storage_file_grace_hours', 0, 'zero or more hours'),
            authorizerResolver: self::implementing($c['authorization']['resolver'], AuthorizesDocumentAccess::class, 'authorization.resolver'),
            dedupScopeResolver: self::implementing($c['dedup']['scope_resolver'], ResolvesDedupScope::class, 'dedup.scope_resolver'),
            scanner: self::implementing($c['security']['scanner'], ScansUploadedFile::class, 'security.scanner'),
            allowedDocumentableTypes: $allowed,
            allowGuestUserId: self::bool($c['security']['allow_guest_user_id'], 'security.allow_guest_user_id'),
            scanErrorPolicy: $scanPolicy,
            urlMaxTtlMinutes: self::int($c['url']['max_ttl_minutes'], 'url.max_ttl_minutes', 1, 'a positive number of minutes'),
            storagePathGenerator: self::implementing($c['storage_path']['generator'], GeneratesStoragePath::class, 'storage_path.generator'),
            disks: self::arrayValue($c['disks'], 'disks'),
            middleware: $middleware,
            listingPerPage: $perPage,
            throttle: self::string($c['throttle'], 'throttle', 'Name the rate limiter the routes are mounted behind, for example documents'),
            auditEnabled: self::bool($c['audit']['enabled'], 'audit.enabled'),
            auditAccessLog: self::bool($c['audit']['access_log'], 'audit.access_log'),
        );
    }

    /**
     * Built-in defaults. A test keeps this identical to `config/documentable.php`; it exists because
     * `mergeConfigFrom()` is shallow, so an older published config can lack keys inside a section.
     *
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'disk' => 's3',
            'key_type' => 'string',
            'load_migrations' => true,
            'load_routes' => true,
            'types' => [],
            'multipart' => [
                'threshold_bytes' => 10 * 1024 * 1024,
                'etag_strategy' => 'server-authoritative',
                'part_upload_url_ttl' => '+1 hour',
                'session_ttl_hours' => 24,
                'use_native_checksum' => false,
                'drivers' => ['s3' => S3MultipartDriver::class],
            ],
            'lifecycle' => [
                'reaper_frequency' => 'hourly',
                'storage_file_grace_hours' => 24,
            ],
            'authorization' => ['resolver' => null],
            'dedup' => ['scope_resolver' => null],
            'security' => [
                'scanner' => null,
                'allowed_documentable_types' => null,
                'allow_guest_user_id' => false,
                'scan_error_policy' => 'reject',
            ],
            'url' => ['max_ttl_minutes' => 1440],
            'storage_path' => ['generator' => null],
            'disks' => [],
            'middleware' => ['api'],
            'listing' => ['per_page' => 50],
            'throttle' => 'documents',
            'audit' => ['enabled' => false, 'access_log' => false],
        ];
    }

    /**
     * Sections with a fixed shape are merged one level deep. Free-form values (`types`, `disks`,
     * `middleware`, the driver map) are taken from the config whole, so an empty list stays empty.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private static function withDefaults(array $config): array
    {
        $merged = self::defaults();

        foreach ($config as $key => $value) {
            if (! array_key_exists($key, $merged)) {
                continue;
            }

            $merged[$key] = is_array($value) && is_array($merged[$key]) && self::isFixedSection($key)
                ? self::mergeSection($merged[$key], $value)
                : $value;
        }

        return $merged;
    }

    private static function isFixedSection(string $key): bool
    {
        return in_array($key, ['multipart', 'lifecycle', 'authorization', 'dedup', 'security', 'url', 'storage_path', 'listing', 'audit'], true);
    }

    /**
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function mergeSection(array $defaults, array $values): array
    {
        // The driver map is free-form: a custom map replaces the default rather than extending it.
        return array_replace($defaults, $values);
    }

    /**
     * @param  list<string>  $knownDisks
     */
    private static function requireDisk(string $disk, string $key, array $knownDisks): void
    {
        if (! in_array($disk, $knownDisks, true)) {
            throw new InvalidDocumentableConfig($key, "names the disk '{$disk}', which is not defined", 'Add it to config/filesystems.php or point the key at an existing disk');
        }
    }

    private static function string(mixed $value, string $key, string $fix): string
    {
        if (! is_string($value) || $value === '') {
            throw new InvalidDocumentableConfig($key, 'must be a non-empty string', $fix);
        }

        return $value;
    }

    private static function bool(mixed $value, string $key): bool
    {
        if (! is_bool($value)) {
            throw new InvalidDocumentableConfig($key, 'must be a boolean', 'Use true or false');
        }

        return $value;
    }

    private static function int(mixed $value, string $key, int $min, string $expected): int
    {
        if (! is_int($value) || $value < $min) {
            throw new InvalidDocumentableConfig($key, "must be an integer of {$min} or more", "Use {$expected}");
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayValue(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new InvalidDocumentableConfig($key, 'must be an array', 'Use an array, or leave the key out for the default');
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $values
     */
    private static function allStrings(array $values): bool
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return class-string<T>|null
     */
    private static function implementing(mixed $class, string $contract, string $key): ?string
    {
        if ($class === null) {
            return null;
        }

        if (! is_string($class) || ! is_a($class, $contract, true)) {
            $shown = is_string($class) ? $class : get_debug_type($class);

            throw new InvalidDocumentableConfig($key, "binds '{$shown}', which does not implement {$contract}", 'Bind a class that implements the contract, or set the key to null for the default');
        }

        return $class;
    }

    /**
     * A frequency is the name of a zero-argument method on the scheduler event, such as `hourly`.
     */
    private static function frequency(mixed $value): string
    {
        $fix = "Use the name of a scheduler frequency method that takes no arguments, for example 'hourly', 'daily' or 'everyFiveMinutes'";

        if (! is_string($value) || ! method_exists(Event::class, $value)) {
            throw new InvalidDocumentableConfig('lifecycle.reaper_frequency', 'is not a scheduler frequency', $fix);
        }

        if ((new ReflectionMethod(Event::class, $value))->getNumberOfRequiredParameters() > 0) {
            throw new InvalidDocumentableConfig('lifecycle.reaper_frequency', "names '{$value}', which needs arguments", $fix);
        }

        return $value;
    }
}
