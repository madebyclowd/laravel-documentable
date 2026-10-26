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
            'stable after a prerelease uses the bump' => ['v3.0.0-rc.2', 'minor', 'stable', '3.1.0'],
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

    private function git(string $args): void
    {
        exec(sprintf('cd %s && git %s 2>&1', escapeshellarg($this->dir), $args), $output, $status);
        $this->assertSame(0, $status, implode("\n", $output));
    }
}
