<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What `php artisan boost:install` / `boost:update` copies into a consuming app is the first thing its AI agent
 * reads about this package, so it is held to the same standard as code: it is named after the package, its
 * skill is discoverable by trigger wording, and it does not repeat advice the package has since corrected.
 */
class BoostDistributionTest extends TestCase
{
    private const SLUG = 'laravel-documentable';

    private const UPGRADE_SLUG = 'laravel-documentable-upgrade-v3';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    private function guideline(): string
    {
        return $this->root.'/resources/boost/guidelines/'.self::SLUG.'.blade.php';
    }

    private function skill(): string
    {
        return $this->root.'/resources/boost/skills/'.self::SLUG.'/SKILL.md';
    }

    private function upgradeSkill(): string
    {
        return $this->root.'/resources/boost/skills/'.self::UPGRADE_SLUG.'/SKILL.md';
    }

    /**
     * @return array<string, string>
     */
    private function frontmatter(?string $file = null): array
    {
        $targetFile = $file ?? $this->skill();
        preg_match('/\A---\n(.*?)\n---\n/s', (string) file_get_contents($targetFile), $block);

        $this->assertNotEmpty($block, basename($targetFile).' must start with a frontmatter block.');

        $fields = [];
        $parent = null;

        foreach (explode("\n", $block[1]) as $line) {
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $m)) {
                $fields[$m[1]] = trim($m[2], " \"'");
                $parent = $m[2] === '' ? $m[1] : null;
            } elseif ($parent !== null && preg_match('/^\s+(\w+):\s*(.+)$/', $line, $m)) {
                $fields[$parent.'.'.$m[1]] = trim($m[2], " \"'");
            }
        }

        return $fields;
    }

    public function test_the_guideline_is_named_after_the_package(): void
    {
        $this->assertFileExists($this->guideline());
        $this->assertFileDoesNotExist($this->root.'/resources/boost/guidelines/core.blade.php');
    }

    public function test_the_package_slug_matches_composer(): void
    {
        $name = json_decode((string) file_get_contents($this->root.'/composer.json'), true)['name'];

        $this->assertSame('madebyclowd/'.self::SLUG, $name);
    }

    public function test_the_skill_directory_matches_the_skill_name(): void
    {
        $this->assertSame(self::SLUG, $this->frontmatter()['name'] ?? null);
    }

    public function test_the_skill_declares_license_and_author(): void
    {
        $fields = $this->frontmatter();

        $this->assertSame('MIT', $fields['license'] ?? null);
        $this->assertSame('madebyclowd', $fields['metadata.author'] ?? null);
    }

    public function test_the_skill_description_tells_an_agent_when_to_use_it(): void
    {
        $description = $this->frontmatter()['description'] ?? '';

        $this->assertStringContainsString('ALWAYS use this skill when', $description);
        $this->assertStringContainsString('madebyclowd/laravel-documentable', $description);
        $this->assertGreaterThan(200, strlen($description));
    }

    public function test_the_guideline_is_a_short_orientation_that_points_at_the_skill(): void
    {
        $lines = count(file($this->guideline()));
        $text = (string) file_get_contents($this->guideline());

        $this->assertLessThanOrEqual(60, $lines, 'The guideline loads into every agent session; keep depth in the skill.');

        foreach (['### Core conventions', '### Operational commands', '### Pitfalls', 'Agent Skill'] as $heading) {
            $this->assertStringContainsString($heading, $text);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function staleAdvice(): array
    {
        return [
            'finalize deleting the object' => ['deletes the object and returns a validation error'],
            'user_id as the owner fallback' => ['Omit `user_id` to fall back'],
            'silent 403 on a null owner' => ['silently 403'],
            'permissive default as acceptable' => ['intentionally permissive'],
            'null owner allowed' => ['null => true'],
            'phase references' => ['phase 1'],
            'canUpload method' => ['canUpload'],
            'default allows everything' => ['allows everything'],
            'allow_guest_user_id config' => ['allow_guest_user_id'],
        ];
    }

    #[DataProvider('staleAdvice')]
    public function test_neither_file_repeats_advice_the_package_has_since_corrected(string $phrase): void
    {
        foreach ([$this->guideline(), $this->skill()] as $file) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, (string) file_get_contents($file), basename($file));
        }
    }

    public function test_the_guideline_does_not_list_install_as_an_operational_command(): void
    {
        $text = (string) file_get_contents($this->guideline());
        preg_match('/### Operational commands(.*?)(?=###|\Z)/s', $text, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString('documents:install', $matches[1]);
    }

    public function test_the_guideline_and_skill_carry_the_operational_facts_agents_get_wrong(): void
    {
        $text = file_get_contents($this->guideline()).file_get_contents($this->skill());

        foreach (['documents:sync-types', "'throw' => true", 'ShouldQueueAfterCommit', 'enforceMorphMap', 'GateDocumentAuthorizer', 'documents:doctor', 'Documentable::fake()'] as $fact) {
            $this->assertStringContainsString($fact, $text, "Missing: {$fact}");
        }
    }

    public function test_the_upgrade_skill_exists_and_directory_matches_name(): void
    {
        $this->assertFileExists($this->upgradeSkill());
        $this->assertSame(self::UPGRADE_SLUG, $this->frontmatter($this->upgradeSkill())['name'] ?? null);
    }

    public function test_the_upgrade_skill_declares_license_and_author(): void
    {
        $fields = $this->frontmatter($this->upgradeSkill());

        $this->assertSame('MIT', $fields['license'] ?? null);
        $this->assertSame('madebyclowd', $fields['metadata.author'] ?? null);
    }

    public function test_the_upgrade_skill_description_starts_with_trigger_wording(): void
    {
        $description = $this->frontmatter($this->upgradeSkill())['description'] ?? '';

        $this->assertStringStartsWith('Use when upgrading madebyclowd/laravel-documentable to 3.x', $description);
    }

    public function test_the_upgrade_skill_notes_removal_in_v3_1(): void
    {
        $text = (string) file_get_contents($this->upgradeSkill());

        $this->assertMatchesRegularExpression('/removed in v3\.1/i', $text);
    }

    public function test_every_scanner_rule_id_from_fixtures_appears_in_the_upgrade_skill(): void
    {
        $fixturesDir = $this->root.'/tests/Fixtures/UpgradeApps';
        $ruleIds = array_values(array_filter(
            scandir($fixturesDir),
            fn ($dir) => ! in_array($dir, ['.', '..', 'clean'], true) && is_dir($fixturesDir.'/'.$dir)
        ));

        $this->assertNotEmpty($ruleIds);

        $upgradeSkillContent = (string) file_get_contents($this->upgradeSkill());

        foreach ($ruleIds as $ruleId) {
            $this->assertStringContainsString($ruleId, $upgradeSkillContent, "Missing scanner rule ID: {$ruleId}");
        }
    }

    public function test_the_main_skill_is_300_lines_or_fewer(): void
    {
        $lines = count(file($this->skill()));

        $this->assertLessThanOrEqual(300, $lines, 'The main skill must be 300 lines or fewer.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function processWords(): array
    {
        return [
            'impl' => ['/\bimpl\b/i'],
            'phase' => ['/\bphase\b/i'],
            'adr' => ['/\badr\b/i'],
            'vault' => ['/\bvault\b/i'],
            'agy' => ['/\bagy\b/i'],
            'claude' => ['/\bclaude\b/i'],
            'owner decided' => ['/owner decided/i'],
        ];
    }

    #[DataProvider('processWords')]
    public function test_shipped_files_do_not_contain_process_words(string $pattern): void
    {
        $files = [$this->guideline(), $this->skill()];
        if (file_exists($this->upgradeSkill())) {
            $files[] = $this->upgradeSkill();
        }

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression($pattern, (string) file_get_contents($file), basename($file).' contains forbidden process word.');
        }
    }

    public function test_the_readme_points_at_the_real_file_names_and_the_update_command(): void
    {
        $readme = (string) file_get_contents($this->root.'/README.md');

        $this->assertStringContainsString('laravel-documentable.blade.php', $readme);
        $this->assertStringContainsString('boost:update', $readme);
        $this->assertStringNotContainsString('core.blade.php', $readme);
    }
}
