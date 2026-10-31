<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit\Upgrade;

use MadeByClowd\Documentable\Upgrade\CodeScanner;
use PHPUnit\Framework\TestCase;

class CodeScannerTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesPath = dirname(__DIR__, 2).'/Fixtures/UpgradeApps';
    }

    public function test_clean_tree_reports_no_findings(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/clean');

        $this->assertSame([], $findings);
    }

    public function test_flags_documentservice_subclass_and_protected_members(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/documentservice-subclass');

        $this->assertCount(2, $findings);

        $this->assertSame('documentservice-subclass', $findings[0]->rule);
        $this->assertSame('app/Services/CustomDocumentService.php', $findings[0]->file);
        $this->assertSame(9, $findings[0]->line);
        $this->assertSame('Compose with the public seams (`attachStorageFile`, `readStream`, `readContents`, hooks) instead of subclassing.', $findings[0]->replacement);
        $this->assertSame('#documentservice-internals-sealed', $findings[0]->anchor);

        $this->assertSame('documentservice-subclass', $findings[1]->rule);
        $this->assertSame('app/Services/CustomDocumentService.php', $findings[1]->file);
        $this->assertSame(13, $findings[1]->line);
        $this->assertSame('Compose with the public seams (`attachStorageFile`, `readStream`, `readContents`, hooks) instead of subclassing.', $findings[1]->replacement);
        $this->assertSame('#documentservice-internals-sealed', $findings[1]->anchor);
    }

    public function test_flags_removed_upload_api_and_ignores_pure_comments(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/removed-upload-api');

        $this->assertCount(5, $findings);

        $expectedLines = [16, 17, 18, 19, 20];
        foreach ($findings as $i => $finding) {
            $this->assertSame('removed-upload-api', $finding->rule);
            $this->assertSame('app/Services/LegacyUploadService.php', $finding->file);
            $this->assertSame($expectedLines[$i], $finding->line);
            $this->assertSame('Use `attach(session, owner)` and the `processing` status.', $finding->replacement);
            $this->assertSame('#removed-upload-api', $finding->anchor);
        }
    }

    public function test_flags_old_authorizer_and_permissive_authorizer(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/old-authorizer');

        $this->assertCount(3, $findings);

        $this->assertSame('old-authorizer', $findings[0]->rule);
        $this->assertSame('app/Policies/LegacyAuthorizer.php', $findings[0]->file);
        $this->assertSame(9, $findings[0]->line);
        $this->assertSame('Implement the explicit authorizer methods or extend `GateDocumentAuthorizer`.', $findings[0]->replacement);
        $this->assertSame('#authorization-contract-rewritten', $findings[0]->anchor);

        $this->assertSame('old-authorizer', $findings[1]->rule);
        $this->assertSame('app/Policies/LegacyAuthorizer.php', $findings[1]->file);
        $this->assertSame(12, $findings[1]->line);
        $this->assertSame('Implement the explicit authorizer methods or extend `GateDocumentAuthorizer`.', $findings[1]->replacement);
        $this->assertSame('#authorization-contract-rewritten', $findings[1]->anchor);

        $this->assertSame('old-authorizer', $findings[2]->rule);
        $this->assertSame('app/Policies/LegacyAuthorizer.php', $findings[2]->file);
        $this->assertSame(14, $findings[2]->line);
        $this->assertSame('Implement the explicit authorizer methods or extend `GateDocumentAuthorizer`.', $findings[2]->replacement);
        $this->assertSame('#authorization-contract-rewritten', $findings[2]->anchor);
    }

    public function test_flags_native_checksum(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/native-checksum');

        $this->assertCount(2, $findings);

        $this->assertSame('native-checksum', $findings[0]->rule);
        $this->assertSame('app/Services/ChecksumUsage.php', $findings[0]->file);
        $this->assertSame(11, $findings[0]->line);
        $this->assertSame('Removed. Verification is tiered and automatic.', $findings[0]->replacement);
        $this->assertSame('#removed-native-checksum', $findings[0]->anchor);

        $this->assertSame('native-checksum', $findings[1]->rule);
        $this->assertSame('config/documentable.php', $findings[1]->file);
        $this->assertSame(7, $findings[1]->line);
        $this->assertSame('Removed. Verification is tiered and automatic.', $findings[1]->replacement);
        $this->assertSame('#removed-native-checksum', $findings[1]->anchor);
    }

    public function test_flags_renamed_config_in_config_only(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/renamed-config');

        $this->assertCount(4, $findings);

        $this->assertSame('renamed-config', $findings[0]->rule);
        $this->assertSame('config/documentable.php', $findings[0]->file);
        $this->assertSame(8, $findings[0]->line);
        $this->assertSame('versions_kept', $findings[0]->replacement);
        $this->assertSame('#renamed-config-keys', $findings[0]->anchor);

        $this->assertSame('renamed-config', $findings[1]->rule);
        $this->assertSame('config/documentable.php', $findings[1]->file);
        $this->assertSame(12, $findings[1]->line);
        $this->assertSame('multipart.session_ttl_hours', $findings[1]->replacement);
        $this->assertSame('#renamed-config-keys', $findings[1]->anchor);

        $this->assertSame('renamed-config', $findings[2]->rule);
        $this->assertSame('config/documentable.php', $findings[2]->file);
        $this->assertSame(15, $findings[2]->line);
        $this->assertSame('#renamed-config-keys', $findings[2]->anchor);

        $this->assertSame('renamed-config', $findings[3]->rule);
        $this->assertSame('config/documentable.php', $findings[3]->file);
        $this->assertSame(16, $findings[3]->line);
        $this->assertSame('#renamed-config-keys', $findings[3]->anchor);
    }

    public function test_flags_direct_storage_read(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/direct-storage-read');

        $this->assertCount(1, $findings);

        $this->assertSame('direct-storage-read', $findings[0]->rule);
        $this->assertSame('app/Services/DirectReader.php', $findings[0]->file);
        $this->assertSame(14, $findings[0]->line);
        $this->assertSame('Use `DocumentService::readContents()` or `readStream()`.', $findings[0]->replacement);
        $this->assertSame('#direct-storage-reads', $findings[0]->anchor);
    }

    public function test_flags_document_type_query(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/document-type-query');

        $this->assertCount(1, $findings);

        $this->assertSame('document-type-query', $findings[0]->rule);
        $this->assertSame('app/Repositories/DocQuery.php', $findings[0]->file);
        $this->assertSame(11, $findings[0]->line);
        $this->assertSame('Use the `ofType($code)` scope.', $findings[0]->replacement);
        $this->assertSame('#document-type-queries', $findings[0]->anchor);
    }

    public function test_flags_multipart_driver_contract(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/multipart-driver-contract');

        $this->assertCount(1, $findings);

        $this->assertSame('multipart-driver-contract', $findings[0]->rule);
        $this->assertSame('app/Drivers/CustomDriver.php', $findings[0]->file);
        $this->assertSame(9, $findings[0]->line);
        $this->assertSame('The contract changed. Run the conformance suite.', $findings[0]->replacement);
        $this->assertSame('#multipartuploaddriver-contract', $findings[0]->anchor);
    }

    public function test_flags_fake_driver_moved(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/fake-driver-moved');

        $this->assertCount(1, $findings);

        $this->assertSame('fake-driver-moved', $findings[0]->rule);
        $this->assertSame('app/Testing/FakeDriverUsage.php', $findings[0]->file);
        $this->assertSame(7, $findings[0]->line);
        $this->assertSame('MadeByClowd\Documentable\Testing\FakeMultipartUploadDriver', $findings[0]->replacement);
        $this->assertSame('#testing-fake-driver-moved', $findings[0]->anchor);
    }

    public function test_flags_package_migration_divergence_only_when_different(): void
    {
        $scanner = new CodeScanner;
        $findings = $scanner->scan($this->fixturesPath.'/package-migration-diverges');

        $this->assertCount(1, $findings);

        $this->assertSame('package-migration-diverges', $findings[0]->rule);
        $this->assertSame('database/migrations/2026_07_05_000001_create_document_types_table.php', $findings[0]->file);
        $this->assertSame(1, $findings[0]->line);
        $this->assertSame('Re-publish the package migration, or keep your copy and confirm the divergence is intended.', $findings[0]->replacement);
        $this->assertSame('#package-migrations', $findings[0]->anchor);
    }

    public function test_missing_directories_do_not_crash(): void
    {
        $tempDir = sys_get_temp_dir().'/upgrade_scan_test_'.uniqid();
        mkdir($tempDir.'/app', 0777, true);
        file_put_contents($tempDir.'/app/Simple.php', "<?php\n");

        try {
            $scanner = new CodeScanner;
            $findings = $scanner->scan($tempDir);
            $this->assertSame([], $findings);
        } finally {
            @unlink($tempDir.'/app/Simple.php');
            @rmdir($tempDir.'/app');
            @rmdir($tempDir);
        }
    }
}
