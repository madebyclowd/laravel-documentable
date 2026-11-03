#!/usr/bin/env php
<?php

/**
 * Aggregates pending .changes/*.md changeset fragments into a single new
 * CHANGELOG.md entry, computes the next semver version from the highest
 * bump level among them, deletes the consumed fragments, and prints the
 * new version number to stdout.
 *
 * Set PRERELEASE_CHANNEL (for example `alpha`) on a release branch such as 3.x to produce prerelease
 * versions: the first one targets the bumped version (3.0.0-alpha.1), each later one increments the
 * counter (3.0.0-alpha.2) until a run without the variable, or with the value `stable`, cuts the stable
 * version: the version the prereleases were heading for (3.0.0), whatever the bump.
 *
 * Exits with status 1 (and nothing on stdout) if there are no pending
 * changesets — that's the signal to the calling workflow that there's
 * nothing to release.
 */
const REPO_URL = 'https://github.com/madebyclowd/laravel-documentable';
const TYPE_ORDER = ['Added', 'Changed', 'Fixed', 'Removed', 'Deprecated', 'Security'];
const BUMP_PRIORITY = ['major' => 3, 'minor' => 2, 'patch' => 1];

$root = dirname(__DIR__);
$changesDir = $root.'/.changes';
$changelogPath = $root.'/CHANGELOG.md';

$fragments = collectFragments($changesDir);

if ($fragments === []) {
    fwrite(STDERR, "No pending changesets.\n");
    exit(1);
}

$bumpLevel = 'patch';
foreach ($fragments as $fragment) {
    if (BUMP_PRIORITY[$fragment['bump']] > BUMP_PRIORITY[$bumpLevel]) {
        $bumpLevel = $fragment['bump'];
    }
}

$latestTag = latestVersionTag();
$channel = trim((string) getenv('PRERELEASE_CHANNEL'));
$channel = $channel === 'stable' ? '' : $channel;
$newVersion = $channel !== ''
    ? nextPrereleaseVersion($latestTag, $bumpLevel, $channel)
    : bumpVersion($latestTag, $bumpLevel);

$entry = buildChangelogEntry($newVersion, $fragments);

writeChangelog($changelogPath, $entry, $newVersion, $latestTag);

foreach ($fragments as $fragment) {
    unlink($fragment['file']);
}

echo $newVersion."\n";

function collectFragments(string $changesDir): array
{
    $fragments = [];

    foreach (glob($changesDir.'/*.md') as $file) {
        $basename = basename($file);

        if (in_array($basename, ['README.md', 'TEMPLATE.md'], true)) {
            continue;
        }

        $contents = file_get_contents($file);

        if (! preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $contents, $matches)) {
            fwrite(STDERR, "Skipping {$basename}: missing frontmatter.\n");

            continue;
        }

        $meta = [];
        foreach (preg_split('/\r?\n/', trim($matches[1])) as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $meta[trim($key)] = trim($value);
        }

        $bump = $meta['bump'] ?? 'patch';
        $type = $meta['type'] ?? 'Changed';
        $body = trim($matches[2]);

        if (! isset(BUMP_PRIORITY[$bump])) {
            fwrite(STDERR, "Skipping {$basename}: invalid bump '{$bump}'.\n");

            continue;
        }

        if ($body === '') {
            fwrite(STDERR, "Skipping {$basename}: empty body.\n");

            continue;
        }

        $fragments[] = ['file' => $file, 'bump' => $bump, 'type' => $type, 'body' => $body];
    }

    return $fragments;
}

/**
 * The newest version tag in the repository, by version, not by what the current commit can reach.
 *
 * `git describe` only sees tags reachable from HEAD. A long-lived branch that lands on main as a squash commit
 * (linear history required) leaves its tags unreachable, and describe then reports the last tag main happened to
 * contain, so the next version would be computed from the wrong tag.
 */
function latestVersionTag(): string
{
    $tags = array_filter(
        array_map('trim', explode("\n", (string) shell_exec("git tag --list 'v*' 2>/dev/null"))),
        fn (string $tag) => preg_match('/^v\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', $tag) === 1
    );

    if ($tags === []) {
        return 'v0.0.0';
    }

    usort($tags, fn (string $a, string $b) => version_compare(ltrim($a, 'v'), ltrim($b, 'v')));

    return end($tags);
}

function nextPrereleaseVersion(string $latestTag, string $bumpLevel, string $channel): string
{
    $quoted = preg_quote($channel, '/');

    // Already on this channel: keep the target version and bump the counter.
    if (preg_match('/^v?(\d+\.\d+\.\d+)-'.$quoted.'\.(\d+)$/', $latestTag, $m)) {
        return $m[1].'-'.$channel.'.'.((int) $m[2] + 1);
    }

    // Moving between channels (alpha to rc): keep the target version, restart the counter.
    if (preg_match('/^v?(\d+\.\d+\.\d+)-[0-9A-Za-z]+\.\d+$/', $latestTag, $m)) {
        return $m[1].'-'.$channel.'.1';
    }

    return bumpVersion($latestTag, $bumpLevel).'-'.$channel.'.1';
}

function bumpVersion(string $latestTag, string $bumpLevel): string
{
    // A prerelease already points at the version it is heading for, so the stable release is that version,
    // whatever the pending changesets ask for. Bumping it again would skip 3.0.0 for 3.0.1.
    if (preg_match('/^v?(\d+\.\d+\.\d+)-[0-9A-Za-z.]+$/', $latestTag, $m)) {
        return $m[1];
    }

    sscanf(ltrim($latestTag, 'v'), '%d.%d.%d', $major, $minor, $patch);
    $major ??= 0;
    $minor ??= 0;
    $patch ??= 0;

    return match ($bumpLevel) {
        'major' => ($major + 1).'.0.0',
        'minor' => $major.'.'.($minor + 1).'.0',
        'patch' => $major.'.'.$minor.'.'.($patch + 1),
    };
}

function buildChangelogEntry(string $version, array $fragments): string
{
    $byType = [];
    foreach ($fragments as $fragment) {
        $byType[$fragment['type']][] = $fragment['body'];
    }

    uksort($byType, fn ($a, $b) => (array_search($a, TYPE_ORDER, true) ?: 99) <=> (array_search($b, TYPE_ORDER, true) ?: 99));

    $entry = '## ['.$version.'] - '.date('Y-m-d')."\n";

    foreach ($byType as $type => $bodies) {
        $entry .= "\n### {$type}\n";
        foreach ($bodies as $body) {
            $lines = preg_split('/\r?\n/', $body);
            $entry .= '- '.array_shift($lines)."\n";
            foreach ($lines as $line) {
                $entry .= '  '.$line."\n";
            }
        }
    }

    return $entry;
}

function writeChangelog(string $path, string $entry, string $newVersion, string $latestTag): void
{
    $changelog = file_get_contents($path);

    // New entries go directly above the first existing version heading
    // (right after the title/intro), matching how this file was already
    // being maintained by hand.
    $marker = "\n## [";
    $insertPos = strpos($changelog, $marker);
    $insertPos = $insertPos === false ? strlen(rtrim($changelog))
        : $insertPos + 1;

    $changelog = rtrim(substr($changelog, 0, $insertPos))."\n\n".$entry."\n".ltrim(substr($changelog, $insertPos));

    $footerLink = "[{$newVersion}]: ".REPO_URL."/compare/{$latestTag}...v{$newVersion}\n";
    $changelog = rtrim($changelog)."\n\n".$footerLink;

    file_put_contents($path, $changelog);
}
