<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\Upgrade\CodeScanner;

class UpgradeGuideTest extends TestCase
{
    private string $root;

    private string $upgradeGuidePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->upgradeGuidePath = $this->root.'/UPGRADE.md';
    }

    public function test_upgrade_guide_contains_requirements_and_ordered_migration_steps(): void
    {
        $content = (string) file_get_contents($this->upgradeGuidePath);

        $this->assertStringContainsString('PHP ^8.3', $content);
        $this->assertTrue(
            str_contains($content, 'Laravel ^12') || str_contains($content, 'Laravel ^12|^13'),
            'Requirements must mention Laravel ^12|^13.'
        );

        $requiredSteps = [
            'composer require madebyclowd/laravel-documentable:^3.0',
            'php artisan documents:upgrade-check --code',
            'php artisan documents:upgrade-check',
            'php artisan documents:repair --dry-run',
            'php artisan documents:repair --apply',
            'php artisan migrate',
            'php artisan documents:sync-types',
            'php artisan boost:update',
        ];

        foreach ($requiredSteps as $step) {
            $this->assertStringContainsString($step, $content, "UPGRADE.md must contain migration step `{$step}`.");
        }
    }

    public function test_all_artisan_commands_and_options_in_upgrade_guide_exist(): void
    {
        $content = (string) file_get_contents($this->upgradeGuidePath);

        // Match backtick-enclosed `php artisan <command> ...`
        preg_match_all('/`php artisan\s+([a-z0-9:-]+)([^`]*)`/i', $content, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches, 'UPGRADE.md must contain php artisan commands.');

        $artisan = Artisan::all();

        // Optional external commands from companion packages not present in core package dev dependencies
        $externalCommands = ['boost:update'];

        foreach ($matches as $match) {
            $commandName = $match[1];
            $optionsPart = $match[2];

            if (in_array($commandName, $externalCommands, true)) {
                $this->assertStringContainsString(
                    'Laravel Boost',
                    $content,
                    "UPGRADE.md must clarify that {$commandName} is only run when Laravel Boost is installed."
                );

                continue;
            }

            $this->assertArrayHasKey(
                $commandName,
                $artisan,
                "Artisan command `{$commandName}` named in UPGRADE.md must be a registered command."
            );

            $command = $artisan[$commandName];
            $definition = $command->getDefinition();

            preg_match_all('/--([a-z0-9-]+)/', $optionsPart, $optionMatches);
            foreach ($optionMatches[1] as $option) {
                $this->assertTrue(
                    $definition->hasOption($option),
                    "Command `{$commandName}` does not have option `--{$option}` named in UPGRADE.md."
                );
            }
        }
    }

    public function test_all_config_keys_in_upgrade_guide_exist_unless_marked_removed(): void
    {
        $content = (string) file_get_contents($this->upgradeGuidePath);
        $config = require $this->root.'/config/documentable.php';

        $removedKeys = [
            'max_versions_to_keep' => '#renamed-config-keys',
            'pending_ttl_hours' => '#renamed-config-keys',
            'lifecycle.pending_ttl_hours' => '#renamed-config-keys',
            'allow_guest_user_id' => '#renamed-config-keys',
            'security.allow_guest_user_id' => '#renamed-config-keys',
            'enforce_path_prefix' => '#renamed-config-keys',
            'security.enforce_path_prefix' => '#renamed-config-keys',
            'use_native_checksum' => '#removed-native-checksum',
            'multipart.use_native_checksum' => '#removed-native-checksum',
        ];

        $headingSlugs = $this->extractHeadingSlugs($content);

        // Verify each removed key is tied to a section that marks it as removed
        foreach ($removedKeys as $key => $sectionAnchor) {
            $this->assertContains($sectionAnchor, $headingSlugs, "Section anchor {$sectionAnchor} must resolve to a heading.");

            $sectionContent = $this->extractSectionContent($content, $sectionAnchor);
            $bareKey = str_contains($key, '.') ? (string) substr(strrchr($key, '.') ?: '', 1) : $key;

            $this->assertTrue(
                str_contains($sectionContent, $key) || str_contains($sectionContent, $bareKey),
                "Section {$sectionAnchor} must mention key `{$key}`."
            );
            $this->assertTrue(
                str_contains(strtolower($sectionContent), 'remove') || str_contains(strtolower($sectionContent), 'rename'),
                "Section {$sectionAnchor} must mark `{$key}` as removed or renamed."
            );
        }

        // Match backtick keys that look like documentable config keys: `documentable.<path>`
        preg_match_all('/`documentable\.([a-z0-9_.]+)`/i', $content, $docKeyMatches);
        foreach ($docKeyMatches[1] as $keyPath) {
            if (array_key_exists($keyPath, $removedKeys) || array_key_exists('documentable.'.$keyPath, $removedKeys)) {
                continue;
            }

            $this->assertTrue(
                Arr::has($config, $keyPath),
                "Config key `documentable.{$keyPath}` named in UPGRADE.md does not exist in config/documentable.php."
            );
        }

        // Match backtick keys explicitly introduced as config keys: `config key `<name>``
        preg_match_all('/(?:config key|key)\s+`([a-z0-9_.]+)`/i', $content, $explicitKeyMatches);
        foreach ($explicitKeyMatches[1] as $keyName) {
            if (array_key_exists($keyName, $removedKeys)) {
                continue;
            }

            $cleanKey = str_starts_with($keyName, 'documentable.') ? substr($keyName, strlen('documentable.')) : $keyName;
            $exists = Arr::has($config, $cleanKey) || array_key_exists($cleanKey, $config);

            // Also check under known top-level sections if bare key
            if (! $exists) {
                foreach (['types', 'multipart', 'verification', 'lifecycle', 'authorization', 'dedup', 'security', 'url', 'storage_path', 'disks', 'retention'] as $section) {
                    if (Arr::has($config, $section.'.'.$cleanKey)) {
                        $exists = true;
                        break;
                    }
                }
            }

            $this->assertTrue(
                $exists,
                "Config key `{$keyName}` named in UPGRADE.md does not exist in config/documentable.php."
            );
        }
    }

    public function test_all_internal_anchor_links_and_scanner_anchors_resolve_to_headings(): void
    {
        $content = (string) file_get_contents($this->upgradeGuidePath);

        // Collect anchors produced by CodeScanner on all fixtures
        $scanner = new CodeScanner;
        $fixturesPath = $this->root.'/tests/Fixtures/UpgradeApps';
        $scannerAnchors = [];

        foreach ((array) glob($fixturesPath.'/*') as $fixtureDir) {
            if (is_dir($fixtureDir)) {
                foreach ($scanner->scan($fixtureDir) as $finding) {
                    $scannerAnchors[$finding->anchor] = true;
                }
            }
        }
        $scannerAnchors = array_keys($scannerAnchors);
        $this->assertNotEmpty($scannerAnchors, 'CodeScanner fixtures must produce findings with anchors.');

        // Collect all internal markdown links `](#...)`
        preg_match_all('/\]\((#[a-z0-9_-]+)\)/i', $content, $linkMatches);
        $internalLinks = array_unique($linkMatches[1]);

        $headingSlugs = $this->extractHeadingSlugs($content);

        // Every anchor emitted by the scanner must resolve to a heading or anchor
        foreach ($scannerAnchors as $anchor) {
            $this->assertContains(
                $anchor,
                $headingSlugs,
                "Scanner anchor `{$anchor}` does not resolve to any heading or anchor in UPGRADE.md."
            );
        }

        // Also ensure delete-latest-promotes anchor resolves
        $this->assertContains(
            '#delete-latest-promotes',
            $headingSlugs,
            'Section anchor `#delete-latest-promotes` must resolve to a heading or anchor in UPGRADE.md.'
        );

        // Every internal markdown link must resolve
        foreach ($internalLinks as $link) {
            $this->assertContains(
                $link,
                $headingSlugs,
                "Internal link `{$link}` does not resolve to any heading or anchor in UPGRADE.md."
            );
        }
    }

    public function test_all_package_migration_files_appear_in_lock_rollback_table(): void
    {
        $content = (string) file_get_contents($this->upgradeGuidePath);
        $migrationsPath = $this->root.'/database/migrations';
        $migrationFiles = (array) glob($migrationsPath.'/*.php');

        $this->assertNotEmpty($migrationFiles, 'Package must have migration files.');

        foreach ($migrationFiles as $filePath) {
            $fileName = basename($filePath);
            $this->assertStringContainsString(
                $fileName,
                $content,
                "Migration `{$fileName}` must appear in the lock and rollback table in UPGRADE.md."
            );
        }
    }

    public function test_upgrade_guide_contains_no_banned_words(): void
    {
        $content = (string) file_get_contents($this->upgradeGuidePath);

        $bannedPatterns = [
            '/\bimpl\b/i' => 'internal process word "impl"',
            '/\bphase\b/i' => 'internal process word "phase"',
            '/\badr(?:-\d+)?\b/i' => 'internal process word "ADR"',
            '/\bvault\b/i' => 'internal process word "vault"',
            '/\bagy\b/i' => 'internal process word "agy"',
            '/\bclaude\b/i' => 'internal process word "Claude"',
            '/owner decided/i' => 'phrase "owner decided"',
            '/\brobust\b/i' => 'buzzword "robust"',
            '/\bseamless\b/i' => 'buzzword "seamless"',
            '/\bleverage\b/i' => 'buzzword "leverage"',
            '/—/' => 'em dash (—)',
        ];

        foreach ($bannedPatterns as $pattern => $label) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $content,
                "UPGRADE.md must not contain {$label}."
            );
        }
    }

    /**
     * @return list<string>
     */
    private function extractHeadingSlugs(string $content): array
    {
        $headingSlugs = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^#{1,6}\s+(.+)$/', $trimmed, $headingMatch)) {
                $headingText = $headingMatch[1];
                if (preg_match('/<a\s+(?:id|name)=["\']([^"\']+)["\']\s*>\s*<\/a>/i', $headingText, $anchorMatch)) {
                    $headingSlugs[] = '#'.$anchorMatch[1];
                    $headingText = trim(str_replace($anchorMatch[0], '', $headingText));
                }
                $headingSlugs[] = '#'.$this->slugify($headingText);
            }

            if (preg_match_all('/<a\s+(?:id|name)=["\']([^"\']+)["\']\s*>/i', $trimmed, $anchorMatches)) {
                foreach ($anchorMatches[1] as $anchorId) {
                    $headingSlugs[] = '#'.$anchorId;
                }
            }
        }

        return array_values(array_unique($headingSlugs));
    }

    private function extractSectionContent(string $content, string $sectionAnchor): string
    {
        $targetSlug = ltrim($sectionAnchor, '#');
        $lines = explode("\n", $content);
        $capturing = false;
        $captured = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $match)) {
                $headingSlug = $this->slugify($match[2]);
                if (preg_match('/<a\s+(?:id|name)=["\']([^"\']+)["\']\s*>\s*<\/a>/i', $match[2], $anchorMatch)) {
                    if ($anchorMatch[1] === $targetSlug) {
                        $capturing = true;
                        $captured[] = $line;

                        continue;
                    }
                }
                if ($headingSlug === $targetSlug) {
                    $capturing = true;
                    $captured[] = $line;

                    continue;
                } elseif ($capturing) {
                    break;
                }
            } elseif ($capturing) {
                $captured[] = $line;
            }
        }

        return implode("\n", $captured);
    }

    private function slugify(string $text): string
    {
        // Strip HTML anchors
        $text = (string) preg_replace('/<a\s+[^>]*>.*?<\/a>/i', '', $text);
        // Strip markdown links [label](url) -> label
        $text = (string) preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);
        // Strip code backticks, bold, italic
        $text = str_replace(['`', '*', '_'], '', $text);
        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');
        // Replace non-alphanumeric (except hyphen/underscore) with space
        $text = (string) preg_replace('/[^\p{L}\p{Nd}\s_-]+/u', '', $text);
        // Replace whitespace with hyphens
        $text = (string) preg_replace('/\s+/', '-', trim($text));

        return $text;
    }
}
