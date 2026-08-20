#!/usr/bin/env php
<?php

/**
 * Aggregates pending .changes/*.md changeset fragments into a single new
 * CHANGELOG.md entry, computes the next semver version from the highest
 * bump level among them, deletes the consumed fragments, and prints the
 * new version number to stdout.
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

$latestTag = trim((string) shell_exec('git describe --tags --abbrev=0 2>/dev/null'));
$latestTag = $latestTag !== '' ? $latestTag : 'v0.0.0';
$newVersion = bumpVersion($latestTag, $bumpLevel);

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

function bumpVersion(string $latestTag, string $bumpLevel): string
{
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
