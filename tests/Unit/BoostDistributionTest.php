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

    /**
     * @return array<string, string>
     */
    private function frontmatter(): array
    {
        preg_match('/\A---\n(.*?)\n---\n/s', (string) file_get_contents($this->skill()), $block);

        $this->assertNotEmpty($block, 'SKILL.md must start with a frontmatter block.');

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
        ];
    }

    #[DataProvider('staleAdvice')]
    public function test_neither_file_repeats_advice_the_package_has_since_corrected(string $phrase): void
    {
        foreach ([$this->guideline(), $this->skill()] as $file) {
            $this->assertStringNotContainsStringIgnoringCase($phrase, (string) file_get_contents($file), basename($file));
        }
    }

    public function test_the_guideline_and_skill_carry_the_operational_facts_agents_get_wrong(): void
    {
        $text = file_get_contents($this->guideline()).file_get_contents($this->skill());

        foreach (['documents:sync-types', "'throw' => true", 'ShouldQueueAfterCommit', 'allow_guest_user_id', 'enforceMorphMap'] as $fact) {
            $this->assertStringContainsString($fact, $text, "Missing: {$fact}");
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
