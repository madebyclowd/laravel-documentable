<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Defaults\HashOnlyDedupScope;
use MadeByClowd\Documentable\Exceptions\InvalidDocumentableConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

class DocumentableConfigTest extends TestCase
{
    private const DISKS = ['s3', 'local'];

    public function test_defaults_resolve_from_an_empty_config(): void
    {
        $config = DocumentableConfig::fromArray([], self::DISKS);

        $this->assertSame('s3', $config->disk);
        $this->assertSame('string', $config->keyType);
        $this->assertSame([], $config->validators);
        $this->assertSame(50 * 1024 * 1024, $config->syncHashMaxBytes);
        $this->assertNull($config->verificationConnection);
        $this->assertNull($config->verificationQueue);
        $this->assertTrue($config->loadRoutes);
        $this->assertSame(['api'], $config->middleware);
        $this->assertSame('documents', $config->throttle);
        $this->assertSame(10 * 1024 * 1024, $config->multipartThresholdBytes);
        $this->assertSame('server-authoritative', $config->etagStrategy);
        $this->assertSame('hourly', $config->reaperFrequency);
        $this->assertSame('reject', $config->scanErrorPolicy);
        $this->assertNull($config->authorizerResolver);
    }

    public function test_built_in_defaults_match_the_shipped_config_file(): void
    {
        $shipped = require __DIR__.'/../../config/documentable.php';

        $this->assertEquals(
            DocumentableConfig::fromArray([], self::DISKS),
            DocumentableConfig::fromArray($shipped, self::DISKS),
        );
    }

    public function test_a_partial_nested_section_keeps_the_other_defaults(): void
    {
        $config = DocumentableConfig::fromArray(['multipart' => ['etag_strategy' => 'client']], self::DISKS);

        $this->assertSame('client', $config->etagStrategy);
        $this->assertSame(10 * 1024 * 1024, $config->multipartThresholdBytes);
        $this->assertSame(24, $config->sessionTtlHours);
    }

    public function test_the_removed_guest_user_id_key_in_an_old_published_config_is_ignored(): void
    {
        $this->assertEquals(
            DocumentableConfig::fromArray([], self::DISKS),
            DocumentableConfig::fromArray(['security' => ['allow_guest_user_id' => true]], self::DISKS),
        );
    }

    public function test_an_unknown_top_level_key_is_ignored(): void
    {
        $this->assertEquals(
            DocumentableConfig::fromArray([], self::DISKS),
            DocumentableConfig::fromArray(['not_a_documentable_key' => ['x' => 1]], self::DISKS),
        );
    }

    public function test_an_empty_middleware_list_is_kept_not_replaced_by_the_default(): void
    {
        $this->assertSame([], DocumentableConfig::fromArray(['middleware' => []], self::DISKS)->middleware);
    }

    public function test_a_valid_class_binding_is_accepted(): void
    {
        $config = DocumentableConfig::fromArray(['dedup' => ['scope_resolver' => HashOnlyDedupScope::class]], self::DISKS);

        $this->assertSame(HashOnlyDedupScope::class, $config->dedupScopeResolver);
    }

    #[DataProvider('keyTypes')]
    public function test_every_supported_key_type_is_accepted(string $keyType): void
    {
        $this->assertSame($keyType, DocumentableConfig::fromArray(['key_type' => $keyType], self::DISKS)->keyType);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function keyTypes(): array
    {
        return ['int' => ['int'], 'uuid' => ['uuid'], 'ulid' => ['ulid'], 'string' => ['string']];
    }

    #[DataProvider('validPresignEndpoints')]
    public function test_a_presign_endpoint_is_an_http_or_https_url(string $endpoint): void
    {
        $config = DocumentableConfig::fromArray(['disks' => ['s3' => ['presign_endpoint' => $endpoint, 'presign_use_path_style' => true]]], self::DISKS);

        $this->assertSame($endpoint, $config->disks['s3']['presign_endpoint']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validPresignEndpoints(): array
    {
        return [
            'https' => ['https://storage.example.com'],
            'http with a port' => ['http://localhost:9000'],
            'upper case scheme' => ['HTTPS://Storage.Example.com'],
        ];
    }

    public function test_the_removed_native_checksum_setting_is_ignored_not_honoured(): void
    {
        $config = DocumentableConfig::fromArray(['multipart' => ['use_native_checksum' => true]], self::DISKS);

        $this->assertFalse(property_exists($config, 'useNativeChecksum'));
    }

    public function test_the_object_is_immutable(): void
    {
        $this->assertTrue((new ReflectionClass(DocumentableConfig::class))->isReadOnly());
        $this->assertTrue((new ReflectionClass(DocumentableConfig::class))->isFinal());

        $config = DocumentableConfig::fromArray([], self::DISKS);

        $this->expectException(\Error::class);
        $config->disk = 'local'; // @phpstan-ignore-line assigning a readonly property is the point of the test
    }

    /**
     * @param  array<string, mixed>  $input
     */
    #[DataProvider('invalidInputs')]
    public function test_invalid_input_throws_naming_the_key_and_the_fix(array $input, string $key, string $fixFragment): void
    {
        try {
            DocumentableConfig::fromArray($input, self::DISKS);
            $this->fail('Expected InvalidDocumentableConfig for '.$key);
        } catch (InvalidDocumentableConfig $e) {
            $this->assertSame($key, $e->key);
            $this->assertStringContainsString('documentable.'.$key, $e->getMessage());
            $this->assertStringContainsString($fixFragment, $e->getMessage());
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'sync hash limit negative' => [['verification' => ['sync_hash_max_bytes' => -1]], 'verification.sync_hash_max_bytes', 'zero or more'],
            'verification queue empty' => [['verification' => ['queue' => '']], 'verification.queue', 'non-empty string'],
            'verification connection not a string' => [['verification' => ['connection' => 5]], 'verification.connection', 'non-empty string'],
            'validators not a map' => [['validators' => 'dwg'], 'validators', 'map'],
            'validator list not a list' => [['validators' => ['CAD' => 'Foo']], 'validators.CAD', 'list'],
            'validator entry null' => [['validators' => ['CAD' => [null]]], 'validators.CAD', 'class name'],
            'validator not implementing the contract' => [['validators' => ['CAD' => [stdClass::class]]], 'validators.CAD', 'ValidatesUploadedFile'],
            'unknown key type' => [['key_type' => 'bigint'], 'key_type', 'int, uuid, ulid, string'],
            'unknown disk' => [['disk' => 'nope'], 'disk', 'config/filesystems.php'],
            'empty disk' => [['disk' => ''], 'disk', 'config/filesystems.php'],
            'unknown per-type disk' => [['types' => ['invoice' => ['name' => 'Invoice', 'disk' => 'nope']]], 'types.invoice.disk', 'config/filesystems.php'],
            'bad frequency' => [['lifecycle' => ['reaper_frequency' => 'whenever']], 'lifecycle.reaper_frequency', 'hourly'],
            'frequency needing an argument' => [['lifecycle' => ['reaper_frequency' => 'cron']], 'lifecycle.reaper_frequency', 'hourly'],
            'frequency not a string' => [['lifecycle' => ['reaper_frequency' => 60]], 'lifecycle.reaper_frequency', 'hourly'],
            'authorizer not implementing the contract' => [['authorization' => ['resolver' => stdClass::class]], 'authorization.resolver', 'AuthorizesDocumentAccess'],
            'authorizer class missing' => [['authorization' => ['resolver' => 'App\\Missing']], 'authorization.resolver', 'AuthorizesDocumentAccess'],
            'scanner not implementing the contract' => [['security' => ['scanner' => stdClass::class]], 'security.scanner', 'ScansUploadedFile'],
            'dedup resolver not implementing the contract' => [['dedup' => ['scope_resolver' => stdClass::class]], 'dedup.scope_resolver', 'ResolvesDedupScope'],
            'path generator not implementing the contract' => [['storage_path' => ['generator' => stdClass::class]], 'storage_path.generator', 'GeneratesStoragePath'],
            'multipart driver not implementing the contract' => [['multipart' => ['drivers' => ['s3' => stdClass::class]]], 'multipart.drivers.s3', 'MultipartUploadDriver'],
            'multipart driver entry null' => [['multipart' => ['drivers' => ['s3' => null]]], 'multipart.drivers.s3', 'MultipartUploadDriver'],
            'etag strategy unknown' => [['multipart' => ['etag_strategy' => 'magic']], 'multipart.etag_strategy', 'server-authoritative'],
            'threshold zero' => [['multipart' => ['threshold_bytes' => 0]], 'multipart.threshold_bytes', 'positive'],
            'threshold not an int' => [['multipart' => ['threshold_bytes' => '10MB']], 'multipart.threshold_bytes', 'positive'],
            'session ttl negative' => [['multipart' => ['session_ttl_hours' => -1]], 'multipart.session_ttl_hours', 'positive'],
            'part url ttl unparsable' => [['multipart' => ['part_upload_url_ttl' => 'soon']], 'multipart.part_upload_url_ttl', '+1 hour'],
            'grace hours negative' => [['lifecycle' => ['storage_file_grace_hours' => -5]], 'lifecycle.storage_file_grace_hours', 'zero or more'],
            'url max ttl zero' => [['url' => ['max_ttl_minutes' => 0]], 'url.max_ttl_minutes', 'positive'],
            'per page zero' => [['listing' => ['per_page' => 0]], 'listing.per_page', 'between 1 and 1000'],
            'per page too large' => [['listing' => ['per_page' => 5000]], 'listing.per_page', 'between 1 and 1000'],
            'scan policy unknown' => [['security' => ['scan_error_policy' => 'shrug']], 'security.scan_error_policy', 'reject'],
            'middleware not a list' => [['middleware' => 'api'], 'middleware', 'array'],
            'middleware entry not a string' => [['middleware' => ['api', 5]], 'middleware', 'strings'],
            'throttle empty' => [['throttle' => ''], 'throttle', 'limiter'],
            'allowed types not null or array' => [['security' => ['allowed_documentable_types' => 'App\\Models\\Post']], 'security.allowed_documentable_types', 'null or an array'],
            'boolean flag not a boolean' => [['load_routes' => 'yes'], 'load_routes', 'true or false'],
            'types not an array' => [['types' => 'invoice'], 'types', 'array'],
            'presign endpoint not a url' => [['disks' => ['s3' => ['presign_endpoint' => 'not-a-url']]], 'disks.s3.presign_endpoint', 'absolute http or https URL'],
            'presign endpoint relative' => [['disks' => ['s3' => ['presign_endpoint' => '/path']]], 'disks.s3.presign_endpoint', 'absolute http or https URL'],
            'presign endpoint other scheme' => [['disks' => ['s3' => ['presign_endpoint' => 'ftp://storage.example.com']]], 'disks.s3.presign_endpoint', 'absolute http or https URL'],
            'presign endpoint without a scheme' => [['disks' => ['s3' => ['presign_endpoint' => 'storage.example.com']]], 'disks.s3.presign_endpoint', 'absolute http or https URL'],
            'presign endpoint not a string' => [['disks' => ['s3' => ['presign_endpoint' => 443]]], 'disks.s3.presign_endpoint', 'absolute http or https URL'],
            'presign use path style not boolean' => [['disks' => ['s3' => ['presign_use_path_style' => 'yes']]], 'disks.s3.presign_use_path_style', 'true or false'],
            'multipart object metadata not boolean' => [['multipart' => ['object_metadata' => 'yes']], 'multipart.object_metadata', 'true or false'],
            'multipart content type empty' => [['multipart' => ['content_type' => '']], 'multipart.content_type', 'non-empty string'],
            'doctor scratch prefix empty' => [['doctor' => ['scratch_prefix' => '']], 'doctor.scratch_prefix', 'non-empty string'],
        ];
    }
}
