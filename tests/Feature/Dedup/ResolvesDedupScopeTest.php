<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Dedup;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MadeByClowd\Documentable\Contracts\HasDedupTenant;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Defaults\GlobalDedupScope;
use MadeByClowd\Documentable\Defaults\OwnerScopedDedupScope;
use MadeByClowd\Documentable\Defaults\TypeAndTenantScope;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Tests\Fixtures\TenantScopedDedupScope;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class ResolvesDedupScopeTest extends TestCase
{
    use RefreshDatabase;

    private DocumentType $typeA;

    private DocumentType $typeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->typeA = DocumentType::create([
            'code' => 'INVOICE',
            'name' => 'Invoice',
            'max_size_mb' => 5,
            'disk' => 'test_disk',
            'path_prefix' => 'invoices',
        ]);

        $this->typeB = DocumentType::create([
            'code' => 'RECEIPT',
            'name' => 'Receipt',
            'max_size_mb' => 5,
            'disk' => 'test_disk',
            'path_prefix' => 'receipts',
        ]);
    }

    public function test_default_type_and_tenant_scope_is_bound(): void
    {
        $resolver = $this->app->make(ResolvesDedupScope::class);
        $this->assertInstanceOf(TypeAndTenantScope::class, $resolver);
    }

    public function test_type_and_tenant_scope_keys_by_type_tenant_and_hash(): void
    {
        $scope = new TypeAndTenantScope;
        $hash = hash('sha256', 'sample content');

        $tenantModel = new class extends TestModel implements HasDedupTenant
        {
            public function dedupTenantId(): ?string
            {
                return 'tenant-alpha';
            }
        };

        $otherTenantModel = new class extends TestModel implements HasDedupTenant
        {
            public function dedupTenantId(): ?string
            {
                return 'tenant-beta';
            }
        };

        $key1 = $scope->scopeKey($hash, $this->typeA, null, $tenantModel);
        $key2 = $scope->scopeKey($hash, $this->typeA, null, $tenantModel);
        $this->assertSame($key1, $key2);

        // Cross-tenant does not match
        $keyOtherTenant = $scope->scopeKey($hash, $this->typeA, null, $otherTenantModel);
        $this->assertNotSame($key1, $keyOtherTenant);

        // Cross-type does not match
        $keyOtherType = $scope->scopeKey($hash, $this->typeB, null, $tenantModel);
        $this->assertNotSame($key1, $keyOtherType);

        // Deterministic sha256 of delimited string
        $expected = hash('sha256', "{$this->typeA->id}|tenant-alpha|{$hash}");
        $this->assertSame($expected, $key1);
    }

    public function test_type_and_tenant_scope_uses_static_callback(): void
    {
        $scope = new TypeAndTenantScope;
        $hash = hash('sha256', 'sample content');
        $model = TestModel::create(['name' => 'plain']);

        TypeAndTenantScope::resolveTenantUsing(fn (?Model $owner, ?Authenticatable $user, DocumentType $type) => 'callback-tenant');

        try {
            $key = $scope->scopeKey($hash, $this->typeA, null, $model);
            $expected = hash('sha256', "{$this->typeA->id}|callback-tenant|{$hash}");
            $this->assertSame($expected, $key);
        } finally {
            TypeAndTenantScope::resolveTenantUsing(null);
        }
    }

    public function test_global_dedup_scope_returns_bare_hash_across_types_and_tenants(): void
    {
        $scope = new GlobalDedupScope;
        $hash = hash('sha256', 'global content');

        $tenantModel = new class extends TestModel implements HasDedupTenant
        {
            public function dedupTenantId(): ?string
            {
                return 'tenant-alpha';
            }
        };

        $this->assertSame($hash, $scope->scopeKey($hash, $this->typeA, null, $tenantModel));
        $this->assertSame($hash, $scope->scopeKey($hash, $this->typeB, null, null));
    }

    public function test_owner_scoped_dedup_scope_keys_by_type_owner_and_hash(): void
    {
        $scope = new OwnerScopedDedupScope;
        $hash = hash('sha256', 'owner content');
        $owner1 = TestModel::create(['name' => 'Owner 1']);
        $owner2 = TestModel::create(['name' => 'Owner 2']);

        $key1 = $scope->scopeKey($hash, $this->typeA, null, $owner1);
        $key2 = $scope->scopeKey($hash, $this->typeA, null, $owner2);

        $this->assertNotSame($key1, $key2);

        $expected = hash('sha256', "{$this->typeA->id}|{$owner1->getMorphClass()}:{$owner1->getKey()}|{$hash}");
        $this->assertSame($expected, $key1);
    }

    public function test_tenant_scoped_fixture_satisfies_contract(): void
    {
        $scope = new TenantScopedDedupScope;
        $hash = hash('sha256', 'fixture content');
        $owner = TestModel::create(['name' => 'owner']);

        $key = $scope->scopeKey($hash, $this->typeA, null, $owner);
        $this->assertSame("{$owner->getKey()}:{$hash}", $key);
    }
}
