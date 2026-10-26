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
        $this->assertTrue($config->loadRoutes);
        $this->assertSame(['api'], $config->middleware);
        $this->assertSame('documents', $config->throttle);
        $this->assertSame(10 * 1024 * 1024, $config->multipartThresholdBytes);
        $this->assertSame('server-authoritative', $config->etagStrategy);
        $this->assertSame('hourly', $config->reaperFrequency);
        $this->assertSame('reject', $config->scanErrorPolicy);
        $this->assertFalse($config->allowGuestUserId);
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

    public function test_an_empty_middleware_list_is_kept_not_replaced_by_the_default(): void
    {
        $this->assertSame([], DocumentableConfig::fromArray(['middleware' => []], self::DISKS)->middleware);
    }

    public function test_a_valid_class_binding_is_accepted(): void
    {
        $config = DocumentableConfig::fromArray(['dedup' => ['scope_resolver' => HashOnlyDedupScope::class]], self::DISKS);

        $this->assertSame(HashOnlyDedupScope::class, $config->dedupScopeResolver);
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
            'etag strategy unknown' => [['multipart' => ['etag_strategy' => 'magic']], 'multipart.etag_strategy', 'server-authoritative'],
            'threshold zero' => [['multipart' => ['threshold_bytes' => 0]], 'multipart.threshold_bytes', 'positive'],
            'threshold not an int' => [['multipart' => ['threshold_bytes' => '10MB']], 'multipart.threshold_bytes', 'positive'],
            'session ttl negative' => [['multipart' => ['session_ttl_hours' => -1]], 'multipart.session_ttl_hours', 'positive'],
            'part url ttl unparsable' => [['multipart' => ['part_upload_url_ttl' => 'soon']], 'multipart.part_upload_url_ttl', '+1 hour'],
            'pending ttl zero' => [['lifecycle' => ['pending_ttl_hours' => 0]], 'lifecycle.pending_ttl_hours', 'positive'],
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
        ];
    }
}
