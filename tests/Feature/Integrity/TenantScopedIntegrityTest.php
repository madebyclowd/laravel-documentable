<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Integrity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use MadeByClowd\Documentable\Contracts\ResolvesDedupScope;
use MadeByClowd\Documentable\Integrity\IntegrityInspector;
use MadeByClowd\Documentable\Models\StorageFile;
use MadeByClowd\Documentable\Tests\Fixtures\TenantScopedDedupScope;
use MadeByClowd\Documentable\Tests\TestCase;

class TenantScopedIntegrityTest extends TestCase
{
    use BuildsIntegrityFixtures;
    use RefreshDatabase;

    public function test_documents_verify_and_integrity_inspector_pass_with_tenant_scoped_resolver(): void
    {
        $this->app->bind(ResolvesDedupScope::class, TenantScopedDedupScope::class);

        $type = $this->singleSlotType();
        $owner = $this->owner();

        $contentHash = hash('sha256', 'tenant scoped file content');
        $scopedKey = "tenant-{$owner->getKey()}:{$contentHash}";

        $file = StorageFile::create([
            'file_hash' => $scopedKey,
            'content_hash' => $contentHash,
            'disk' => 'test_disk',
            'path' => 'blobs/'.(string) Str::uuid(),
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ]);

        $this->latestDocument($type, $owner, $file);

        // Verify command must pass cleanly without failing on scoped key
        $this->artisan('documents:verify')
            ->expectsOutputToContain('No latest_marker / is_latest drift detected.')
            ->assertExitCode(0);

        // Integrity inspector must pass without blocking findings
        $inspector = $this->app->make(IntegrityInspector::class);
        $report = $inspector->inspect();

        $this->assertFalse($report->hasBlockingFindings());
        $this->assertEmpty($report->duplicateStorageFiles);
        $this->assertEmpty($report->documentsMissingStorageFile);
    }
}
