<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the real bin/aggregate-changesets.php inside a throwaway git repository, so the version it prints
 * is the version the release workflow would tag.
 */
class AggregateChangesetsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/aggregate-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0777, true);
        mkdir($this->dir.'/.changes');
        copy(__DIR__.'/../../bin/aggregate-changesets.php', $this->dir.'/bin/aggregate-changesets.php');
        file_put_contents($this->dir.'/CHANGELOG.md', "# Changelog\n\n## [2.1.3] - 2026-10-25\n\n### Fixed\n- x\n\n[2.1.3]: https://example.test/compare/v2.1.2...v2.1.3\n");

        $this->git('init -q -b 3.x');
        $this->git('config user.email t@example.test');
        $this->git('config user.name t');
        $this->git('add -A');
        $this->git('commit -q -m init');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function versions(): array
    {
        return [
            'stable patch' => ['v2.1.3', 'patch', '', '2.1.4'],
            'stable major' => ['v2.1.3', 'major', '', '3.0.0'],
            'first alpha from a 2.x tag' => ['v2.1.3', 'major', 'alpha', '3.0.0-alpha.1'],
            'next alpha increments the counter' => ['v3.0.0-alpha.1', 'patch', 'alpha', '3.0.0-alpha.2'],
            'a higher bump does not move the alpha target' => ['v3.0.0-alpha.4', 'major', 'alpha', '3.0.0-alpha.5'],
            'alpha to rc restarts the counter' => ['v3.0.0-alpha.4', 'patch', 'rc', '3.0.0-rc.1'],
            'stable after an alpha graduates to the alpha target' => ['v3.0.0-alpha.11', 'patch', 'stable', '3.0.0'],
            'stable after an rc graduates whatever the bump' => ['v3.0.0-rc.2', 'minor', 'stable', '3.0.0'],
            'a higher bump does not move the graduation' => ['v3.0.0-alpha.11', 'major', 'stable', '3.0.0'],
            'an unset channel graduates too' => ['v3.0.0-alpha.11', 'patch', '', '3.0.0'],
            'stable after a stable still bumps' => ['v3.0.0', 'patch', 'stable', '3.0.1'],
        ];
    }

    #[DataProvider('versions')]
    public function test_it_computes_the_next_version(string $latestTag, string $bump, string $channel, string $expected): void
    {
        $this->git('tag '.escapeshellarg($latestTag));
        file_put_contents($this->dir.'/.changes/change.md', "---\nbump: {$bump}\ntype: Changed\n---\n\nSomething changed.\n");

        $output = [];
        exec(sprintf('cd %s && PRERELEASE_CHANNEL=%s php bin/aggregate-changesets.php 2>&1', escapeshellarg($this->dir), escapeshellarg($channel)), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame($expected, trim(implode("\n", $output)));
        $this->assertStringContainsString("## [{$expected}]", (string) file_get_contents($this->dir.'/CHANGELOG.md'));
        $this->assertFileDoesNotExist($this->dir.'/.changes/change.md');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function unreachableTags(): array
    {
        return [
            'a stable tag off this branch still sets the base' => ['v3.0.0', 'stable', '3.0.1'],
            'a prerelease tag off this branch graduates' => ['v3.0.0-alpha.11', 'stable', '3.0.0'],
            'a prerelease tag off this branch keeps counting' => ['v3.0.0-alpha.11', 'alpha', '3.0.0-alpha.12'],
        ];
    }

    /**
     * Squash-only branch protection leaves the tags of a long-lived branch unreachable from main, so
     * `git describe` would report the last tag main happens to contain. The newest version tag in the repo wins.
     */
    #[DataProvider('unreachableTags')]
    public function test_it_uses_the_highest_tag_even_when_it_is_not_reachable_from_head(string $otherTag, string $channel, string $expected): void
    {
        $this->git('tag v2.1.3');
        $this->git('checkout -q -b side');
        file_put_contents($this->dir.'/side.txt', 'x');
        $this->git('add -A');
        $this->git('commit -q -m side');
        $this->git('tag '.escapeshellarg($otherTag));
        $this->git('checkout -q 3.x');
        file_put_contents($this->dir.'/.changes/change.md', "---\nbump: patch\ntype: Changed\n---\n\nSomething changed.\n");

        $output = [];
        exec(sprintf('cd %s && PRERELEASE_CHANNEL=%s php bin/aggregate-changesets.php 2>&1', escapeshellarg($this->dir), escapeshellarg($channel)), $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertSame($expected, trim(implode("\n", $output)));
    }

    private function git(string $args): void
    {
        exec(sprintf('cd %s && git %s 2>&1', escapeshellarg($this->dir), $args), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }
}
