<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Config;

use Illuminate\Console\Scheduling\Event;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Contracts\GeneratesStoragePath;
use MadeByClowd\Documentable\Contracts\ModifiesUrlOptions;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Contracts\ScansUploadedFile;
use MadeByClowd\Documentable\Contracts\ValidatesUploadedFile;
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
     * @param  array<string, list<class-string<ValidatesUploadedFile>>>  $validators  keyed by document type code
     * @param  array<string, list<class-string<ValidatesUploadedFile>>>  $validators  keyed by document type code
     * @param  class-string<AuthorizesDocumentAccess>|null  $authorizerResolver
     * @param  class-string<ResolvesDedupScope>|null  $dedupScopeResolver
     * @param  class-string<ScansUploadedFile>|null  $scanner
     * @param  class-string<GeneratesStoragePath>|null  $storagePathGenerator
     * @param  class-string<ModifiesUrlOptions>|null  $urlModifier
     * @param  list<string>  $retentionHold
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
        public array $multipartDrivers,
        public string $reaperFrequency,
        public int $storageFileGraceHours,
        public int $syncHashMaxBytes,
        public ?string $verificationConnection,
        public ?string $verificationQueue,
        public ?string $authorizerResolver,
        public ?string $dedupScopeResolver,
        public int $dedupChallengeTtl,
        public int $dedupMaxRangeBytes,
        public string $dedupThrottle,
        public ?string $scanner,
        public ?array $allowedDocumentableTypes,
        public array $validators,
        public string $scanErrorPolicy,
        public int $urlMaxTtlMinutes,
        public ?string $storagePathGenerator,
        public array $disks,
        public array $middleware,
        public int $listingPerPage,
        public string $throttle,
        public bool $auditEnabled,
        public bool $auditAccessLog,
        public bool $multipartObjectMetadata = false,
        public ?string $multipartContentType = null,
        public string $doctorScratchPrefix = 'documentable-doctor',
        public array $retentionHold = [],
        public ?string $urlModifier = null,
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

        $objectMetadata = self::bool($multipart['object_metadata'] ?? false, 'multipart.object_metadata');
        $contentType = self::nullableString($multipart['content_type'] ?? null, 'multipart.content_type');

        $doctor = $c['doctor'] ?? [];
        $scratchPrefix = self::string($doctor['scratch_prefix'] ?? 'documentable-doctor', 'doctor.scratch_prefix', 'Use a non-empty string such as documentable-doctor');

        $disks = self::arrayValue($c['disks'], 'disks');
        foreach ($disks as $diskName => $diskSettings) {
            if (! is_array($diskSettings)) {
                throw new InvalidDocumentableConfig("disks.{$diskName}", 'must be an array of settings', "Use ['server_side_encryption' => 'AES256']");
            }

            if (isset($diskSettings['presign_endpoint'])) {
                $endpoint = $diskSettings['presign_endpoint'];
                if (! is_string($endpoint) || ! filter_var($endpoint, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $endpoint)) {
                    throw new InvalidDocumentableConfig("disks.{$diskName}.presign_endpoint", 'must be an absolute http or https URL', "Use a URL such as 'http://localhost:9000' or 'https://public.example.com'");
                }
            }

            if (isset($diskSettings['presign_use_path_style'])) {
                self::bool($diskSettings['presign_use_path_style'], "disks.{$diskName}.presign_use_path_style");
            }
        }

        $scanPolicy = $c['security']['scan_error_policy'];
        if (! in_array($scanPolicy, self::SCAN_ERROR_POLICIES, true)) {
            throw new InvalidDocumentableConfig('security.scan_error_policy', 'must be one of '.implode(', ', self::SCAN_ERROR_POLICIES), "Use 'reject' so a scanner outage cannot silently turn scanning off");
        }

        $retention = $c['retention'] ?? ['hold' => []];
        $hold = $retention['hold'] ?? [];
        if (! is_array($hold) || ! array_is_list($hold) || ! self::allStrings($hold)) {
            throw new InvalidDocumentableConfig('retention.hold', 'must be a list of document or group IDs', "Use a list of strings such as ['...']");
        }

        $allowed = $c['security']['allowed_documentable_types'];
        if ($allowed !== null && ! (is_array($allowed) && array_is_list($allowed) && self::allStrings($allowed))) {
            throw new InvalidDocumentableConfig('security.allowed_documentable_types', 'must be null or an array of class names', 'Use null to reject every unmapped type, or list the model classes you accept');
        }

        $validators = self::validators($c['validators']);

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
            multipartDrivers: $drivers,
            reaperFrequency: self::frequency($c['lifecycle']['reaper_frequency']),
            storageFileGraceHours: self::int($c['lifecycle']['storage_file_grace_hours'], 'lifecycle.storage_file_grace_hours', 0, 'zero or more hours'),
            syncHashMaxBytes: self::int($c['verification']['sync_hash_max_bytes'], 'verification.sync_hash_max_bytes', 0, 'zero or more bytes'),
            verificationConnection: self::nullableString($c['verification']['connection'], 'verification.connection'),
            verificationQueue: self::nullableString($c['verification']['queue'], 'verification.queue'),
            authorizerResolver: self::implementing($c['authorization']['resolver'], AuthorizesDocumentAccess::class, 'authorization.resolver'),
            dedupScopeResolver: self::implementing($c['dedup']['scope_resolver'], ResolvesDedupScope::class, 'dedup.scope_resolver'),
            dedupChallengeTtl: self::int($c['dedup']['challenge_ttl'] ?? 300, 'dedup.challenge_ttl', 1, 'a positive number of seconds'),
            dedupMaxRangeBytes: self::int($c['dedup']['max_range_bytes'] ?? 64 * 1024, 'dedup.max_range_bytes', 1, 'a positive number of bytes'),
            dedupThrottle: self::string($c['dedup']['throttle'] ?? 'documents-handshake', 'dedup.throttle', 'Name the rate limiter for dedup handshake'),
            scanner: self::implementing($c['security']['scanner'], ScansUploadedFile::class, 'security.scanner'),
            allowedDocumentableTypes: $allowed,
            validators: $validators,
            scanErrorPolicy: $scanPolicy,
            urlMaxTtlMinutes: self::int($c['url']['max_ttl_minutes'], 'url.max_ttl_minutes', 1, 'a positive number of minutes'),
            storagePathGenerator: self::implementing($c['storage_path']['generator'], GeneratesStoragePath::class, 'storage_path.generator'),
            disks: self::arrayValue($c['disks'], 'disks'),
            middleware: $middleware,
            listingPerPage: $perPage,
            throttle: self::string($c['throttle'], 'throttle', 'Name the rate limiter the routes are mounted behind, for example documents'),
            auditEnabled: self::bool($c['audit']['enabled'], 'audit.enabled'),
            auditAccessLog: self::bool($c['audit']['access_log'], 'audit.access_log'),
            multipartObjectMetadata: $objectMetadata,
            multipartContentType: $contentType,
            doctorScratchPrefix: $scratchPrefix,
            retentionHold: $hold,
            urlModifier: self::implementing($c['url']['modifier'] ?? null, ModifiesUrlOptions::class, 'url.modifier'),
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
            'validators' => [],
            'multipart' => [
                'threshold_bytes' => 10 * 1024 * 1024,
                'etag_strategy' => 'server-authoritative',
                'part_upload_url_ttl' => '+1 hour',
                'session_ttl_hours' => 24,
                'drivers' => ['s3' => S3MultipartDriver::class],
                'object_metadata' => false,
                'content_type' => null,
            ],
            'lifecycle' => [
                'reaper_frequency' => 'hourly',
                'storage_file_grace_hours' => 24,
            ],
            'authorization' => ['resolver' => null],
            'dedup' => [
                'scope_resolver' => null,
                'challenge_ttl' => 300,
                'max_range_bytes' => 64 * 1024,
                'throttle' => 'documents-handshake',
            ],
            'security' => [
                'scanner' => null,
                'allowed_documentable_types' => null,
                'scan_error_policy' => 'reject',
            ],
            'verification' => ['sync_hash_max_bytes' => 50 * 1024 * 1024, 'connection' => null, 'queue' => null],
            'url' => ['max_ttl_minutes' => 1440, 'modifier' => null],
            'storage_path' => ['generator' => null],
            'disks' => [],
            'middleware' => ['api'],
            'listing' => ['per_page' => 50],
            'throttle' => 'documents',
            'audit' => ['enabled' => false, 'access_log' => false],
            'retention' => [
                'hold' => [],
            ],
            'doctor' => [
                'scratch_prefix' => 'documentable-doctor',
            ],
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
        return in_array($key, ['multipart', 'lifecycle', 'verification', 'authorization', 'dedup', 'security', 'url', 'storage_path', 'listing', 'audit', 'doctor', 'retention'], true);
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

    /**
     * @return array<string, list<class-string<ValidatesUploadedFile>>>
     */
    private static function validators(mixed $value): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidDocumentableConfig('validators', 'must be a map of document type code to validator classes', "Use ['DRAWING' => [DwgValidator::class]]");
        }

        $checked = [];

        foreach ($value as $code => $classes) {
            if (! is_array($classes) || ! array_is_list($classes)) {
                throw new InvalidDocumentableConfig("validators.{$code}", 'must be a list of validator classes', "Use ['{$code}' => [DwgValidator::class]]");
            }

            foreach ($classes as $class) {
                $checked[(string) $code][] = self::implementing($class, ValidatesUploadedFile::class, "validators.{$code}")
                    ?? throw new InvalidDocumentableConfig("validators.{$code}", 'must be a class name', 'Name a class that implements '.ValidatesUploadedFile::class);
            }
        }

        return $checked;
    }

    private static function nullableString(mixed $value, string $key): ?string
    {
        if ($value !== null && (! is_string($value) || $value === '')) {
            throw new InvalidDocumentableConfig($key, 'must be null or a non-empty string', 'Use null for the application default, or name a queue connection or queue');
        }

        return $value;
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
