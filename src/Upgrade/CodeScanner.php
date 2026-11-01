<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Upgrade;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Regex-based scanner for v2 API usage. Heuristic one-time migration aid, removed in v3.1.
 *
 * @internal
 */
final class CodeScanner
{
    private const MIGRATION_DIVERGENCE = 'package-migration-diverges';

    /** @var list<Rule>|null */
    private ?array $rules = null;

    /**
     * @param  list<string>  $ignoredRules  Rule ids to skip, as listed by {@see ruleIds()}
     * @return list<Finding>
     */
    public function scan(string $basePath, array $ignoredRules = []): array
    {
        $basePath = rtrim($basePath, '/\\');
        $files = $this->collectPhpFiles($basePath);
        $findings = [];

        foreach ($files as $filePath) {
            $relativePath = str_replace('\\', '/', ltrim(substr($filePath, strlen($basePath)), '/\\'));
            $this->scanFile($filePath, $relativePath, $ignoredRules, $findings);
        }

        usort($findings, fn (Finding $a, Finding $b) => [$a->file, $a->line] <=> [$b->file, $b->line]);

        return $findings;
    }

    /**
     * @return list<string>
     */
    public function ruleIds(): array
    {
        return array_values(array_unique([
            self::MIGRATION_DIVERGENCE,
            ...array_map(fn (Rule $rule) => $rule->id, $this->rules()),
        ]));
    }

    /**
     * @return list<string>
     */
    private function collectPhpFiles(string $basePath): array
    {
        $scanDirs = ['app', 'config', 'database/migrations', 'routes'];
        $files = [];

        foreach ($scanDirs as $dir) {
            $fullDir = $basePath.'/'.$dir;
            if (! is_dir($fullDir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($fullDir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS)
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $ignoredRules
     * @param  list<Finding>  $findings
     */
    private function scanFile(string $filePath, string $relativePath, array $ignoredRules, array &$findings): void
    {
        $content = (string) file_get_contents($filePath);

        // A migration is history the app wrote for itself: the only thing worth reporting is a published package
        // migration that was edited.
        if (str_starts_with($relativePath, 'database/migrations/')) {
            if (! in_array(self::MIGRATION_DIVERGENCE, $ignoredRules, true)) {
                $this->checkPackageMigrationDivergence($relativePath, $content, $findings);
            }

            return;
        }

        $scopes = [Rule::ANY_FILE];
        if (str_starts_with($relativePath, 'config/')) {
            $scopes[] = Rule::CONFIG_ONLY;
        }
        if (preg_match('/Documentable\\\\|\bDocument\b/', $content)) {
            $scopes[] = Rule::DOCUMENT_FILES;
        }
        if (preg_match('/extends\s+[A-Za-z0-9_\\\\]*DocumentService\b/', $content)) {
            $scopes[] = Rule::SERVICE_SUBCLASSES;
        }

        $rules = array_filter($this->rules(), fn (Rule $rule) => ! in_array($rule->id, $ignoredRules, true));
        $insideBlockComment = false;

        foreach (explode("\n", $content) as $index => $line) {
            if ($this->isComment($line, $insideBlockComment)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (in_array($rule->scope, $scopes, true) && preg_match($rule->pattern, $line, $match)) {
                    $findings[] = new Finding(
                        rule: $rule->id,
                        file: $relativePath,
                        line: $index + 1,
                        message: str_replace('{match}', $match[1] ?? '', $rule->message),
                        replacement: $rule->replacement,
                        anchor: $rule->anchor,
                    );
                }
            }
        }
    }

    /**
     * True for a line that holds only comment text. `$insideBlockComment` carries a block comment across the
     * lines it spans, so the box-drawing banners in a published config file are not read as code.
     */
    private function isComment(string $line, bool &$insideBlockComment): bool
    {
        if ($insideBlockComment) {
            $insideBlockComment = ! str_contains($line, '*/');

            return true;
        }

        if ($this->isCommentLine($line)) {
            $trimmed = ltrim($line);
            $insideBlockComment = str_starts_with($trimmed, '/*') && ! str_contains($trimmed, '*/');

            return true;
        }

        return false;
    }

    private function isCommentLine(string $line): bool
    {
        $trimmed = ltrim($line);

        return $trimmed === ''
            || str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '/*');
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function checkPackageMigrationDivergence(string $relativePath, string $content, array &$findings): void
    {
        $basename = basename($relativePath);
        if (! preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(?<suffix>.+\.php)$/', $basename, $matches)) {
            return;
        }

        $suffix = $matches['suffix'];
        $packageFiles = glob(dirname(__DIR__, 2).'/database/migrations/*_'.$suffix) ?: [];
        if ($packageFiles === []) {
            return;
        }

        $packageContent = (string) file_get_contents($packageFiles[0]);

        $normalize = fn (string $s): string => str_replace(["\r\n", "\r"], "\n", trim($s));

        if ($normalize($content) !== $normalize($packageContent)) {
            $findings[] = new Finding(
                rule: self::MIGRATION_DIVERGENCE,
                file: $relativePath,
                line: 1,
                message: 'A published copy differs from the package migration; re-publish it or confirm the divergence.',
                replacement: 'Re-publish the package migration, or keep your copy and confirm the divergence is intended.',
                anchor: '#package-migrations'
            );
        }
    }

    /**
     * @return list<Rule>
     */
    private function rules(): array
    {
        $seams = 'Compose with the public seams (`attachStorageFile`, `readStream`, `readContents`, hooks) instead of subclassing.';

        return $this->rules ??= [
            new Rule('documentservice-subclass', '/extends\s+[A-Za-z0-9_\\\\]*DocumentService\b/', 'Subclassing `DocumentService` is not supported.', $seams, '#documentservice-internals-sealed'),
            new Rule('documentservice-subclass', '/(?:\$this->|parent::)(createDocumentFromStorageFile|createDocumentFromUploadedPath|getOrCreateStorageFile|logAccess|isStorageFileReferenced|deleteBlob|validateFile|validateSizeAndMime|abortSession|assertPathIsWithinTypePrefix|assertTypeActive|sseOptions|currentActorId|resolveActorId|storeUploadedFile|resolveMultipartDriver|findOwnedSessionOrFail|resolvePartsForCompletion|resolveAssembledFileInfo|detectMimeFromHead|hashAndDetectMime|validateOrDelete|scanOrDelete|verifyIntegrityOrDelete|assertIntegrity|demoteAndResolveVersion|storageFileRepository|documentRepository|multipartUploadRepository|fileScanner|dedupScopeResolver|pathGenerator)\b/', 'Protected member `{match}` was removed from `DocumentService`.', $seams, '#documentservice-internals-sealed', Rule::SERVICE_SUBCLASSES),
            new Rule('removed-upload-api', '/\buploadDetached\s*\(|\breassociateDocument\s*\(|^(?=.*ocument).*->commit\s*\(|\bDocumentStatus::Pending\b|^(?=.*doc).*(?:status.*[\'"]pending[\'"]|[\'"]pending[\'"].*status)/i', 'Removed upload or pending API used.', 'Use `attach(session, owner)` and the `processing` status.', '#removed-upload-api'),
            new Rule('old-authorizer', '/\bPermissiveDocumentAuthorizer\b|\bcanUpload\s*\(/', 'Old authorizer API used.', 'Implement the explicit authorizer methods or extend `GateDocumentAuthorizer`.', '#authorization-contract-rewritten'),
            new Rule('native-checksum', '/\buse_native_checksum\b|\bretrieveChecksum\b/', 'Native checksum API was removed.', 'Removed. Verification is tiered and automatic.', '#removed-native-checksum'),
            new Rule('renamed-config', '/\bmax_versions_to_keep\b/', 'Config key `max_versions_to_keep` was renamed.', 'versions_kept', '#renamed-config-keys', Rule::CONFIG_ONLY),
            new Rule('renamed-config', '/\bpending_ttl_hours\b/', 'Config key `pending_ttl_hours` was renamed.', 'multipart.session_ttl_hours', '#renamed-config-keys', Rule::CONFIG_ONLY),
            new Rule('renamed-config', '/\ballow_guest_user_id\b/', 'Config key `allow_guest_user_id` was removed.', 'Removed. Authenticate requests instead.', '#renamed-config-keys', Rule::CONFIG_ONLY),
            new Rule('renamed-config', '/\benforce_path_prefix\b/', 'Config key `enforce_path_prefix` was removed.', 'Removed. Sessions isolate storage paths.', '#renamed-config-keys', Rule::CONFIG_ONLY),
            new Rule('direct-storage-read', '/Storage::(?:disk\(.*?\)->)?(?:get|readStream|read)\s*\(/', 'Direct storage read in a file that works with documents.', 'Use `DocumentService::readContents()` or `readStream()`.', '#direct-storage-reads', Rule::DOCUMENT_FILES),
            new Rule('document-type-query', '/whereHas\(\s*[\'"]documentType[\'"]/', 'Direct `whereHas(\'documentType\')` query on documents.', 'Use the `ofType($code)` scope.', '#document-type-queries'),
            new Rule('multipart-driver-contract', '/\bimplements\s+[^;{]*\bMultipartUploadDriver\b/', 'Class implements `MultipartUploadDriver`.', 'The contract changed. Run the conformance suite.', '#multipartuploaddriver-contract'),
            new Rule('fake-driver-moved', '/Tests\\\\Fixtures\\\\FakeMultipartUploadDriver/', '`FakeMultipartUploadDriver` moved namespace.', 'MadeByClowd\\Documentable\\Testing\\FakeMultipartUploadDriver', '#testing-fake-driver-moved'),
        ];
    }
}
