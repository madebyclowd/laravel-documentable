<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Http\Controllers\ClientConfigController;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\PartSizePlanner;
use MadeByClowd\Documentable\Tests\Fixtures\ConfigurableAuthorizer;
use MadeByClowd\Documentable\Tests\TestCase;
use ReflectionMethod;

/**
 * `GET /documents/config` tells a browser uploader what the server already knows, so no app has to hard-code
 * the threshold, part size, concurrency or retry policy, or pass them through its own props.
 */
class ClientConfigEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function makeType(array $overrides = []): DocumentType
    {
        return DocumentType::create(array_merge([
            'code' => 'passport',
            'name' => 'Passport',
            'max_size_mb' => 10,
            'allowed_mimes' => ['application/pdf'],
            'allowed_extensions' => ['pdf'],
            'disk' => 'test_disk',
            'path_prefix' => 'docs',
            'allows_multiple' => false,
            'requires_versioning' => true,
        ], $overrides));
    }

    public function test_it_reports_the_numbers_the_client_would_otherwise_hard_code(): void
    {
        $config = $this->app->make(DocumentableConfig::class);

        $this->getJson('/documents/config')
            ->assertOk()
            ->assertJsonPath('threshold_bytes', $config->multipartThresholdBytes)
            ->assertJsonPath('min_part_size', PartSizePlanner::MIN_PART_SIZE)
            ->assertJsonPath('max_parts', PartSizePlanner::MAX_PARTS)
            ->assertJsonPath('max_concurrency', 4)
            ->assertJsonPath('retry', ['attempts' => 3, 'base_delay_ms' => 500, 'max_delay_ms' => 8000])
            ->assertJsonPath('sync_hash_max_bytes', $config->syncHashMaxBytes)
            ->assertJsonPath('etag_strategy', $config->etagStrategy);
    }

    public function test_the_numbers_follow_the_apps_config(): void
    {
        config()->set('documentable.client.max_concurrency', 8);
        config()->set('documentable.client.retry', ['attempts' => 5, 'base_delay_ms' => 200, 'max_delay_ms' => 2000]);
        $this->app->forgetInstance(DocumentableConfig::class);

        $this->getJson('/documents/config')
            ->assertJsonPath('max_concurrency', 8)
            ->assertJsonPath('retry', ['attempts' => 5, 'base_delay_ms' => 200, 'max_delay_ms' => 2000]);
    }

    public function test_each_type_carries_the_limits_in_bytes(): void
    {
        $type = $this->makeType();

        $this->getJson('/documents/config')
            ->assertJsonPath('types.0', [
                'id' => $type->id,
                'code' => 'passport',
                'name' => 'Passport',
                'max_bytes' => 10 * 1024 * 1024,
                'allowed_mimes' => ['application/pdf'],
                'allowed_extensions' => ['pdf'],
                'trust_extension_for_generic_mime' => false,
                'allows_multiple' => false,
            ]);
    }

    public function test_the_action_is_a_public_entry_point(): void
    {
        $this->getJson('/documents/config')->assertOk();

        $this->assertTrue((new ReflectionMethod(ClientConfigController::class, 'show'))->isPublic());
    }

    public function test_a_type_without_limits_sends_empty_lists_not_null(): void
    {
        $this->makeType(['allowed_mimes' => null, 'allowed_extensions' => null]);

        $this->getJson('/documents/config')
            ->assertJsonPath('types.0.allowed_mimes', [])
            ->assertJsonPath('types.0.allowed_extensions', []);
    }

    public function test_retired_types_are_left_out(): void
    {
        $this->makeType(['code' => 'live']);
        $this->makeType(['code' => 'retired'])->delete();

        $this->assertSame(['live'], collect($this->getJson('/documents/config')->json('types'))->pluck('code')->all());
    }

    public function test_only_the_types_the_authorizer_allows_are_listed(): void
    {
        $this->makeType(['code' => 'open']);
        $this->makeType(['code' => 'admin-only']);
        $this->useAuthorizer(ConfigurableAuthorizer::with([
            'canInitiateUpload' => fn ($user, DocumentType $type) => $type->code === 'open',
        ]));

        $this->assertSame(['open'], collect($this->getJson('/documents/config')->json('types'))->pluck('code')->all());
    }

    public function test_the_response_is_not_stored_by_shared_caches(): void
    {
        $cacheControl = (string) $this->getJson('/documents/config')->headers->get('Cache-Control');

        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
    }
}
