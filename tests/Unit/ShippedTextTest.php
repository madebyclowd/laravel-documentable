<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Comments and docs that ship with the package must stand on their own. References to the internal phase
 * documents, the old feedback files or bug lists mean nothing to someone who installed the package from
 * Packagist, and they rot as soon as those files move. The reasoning lives in the maintainers' notes, not here.
 */
class ShippedTextTest extends TestCase
{
    private const PATTERN = '#phase[ -]?[0-9]|docs/feedbacks|docs/implementations|bugs\.md|best-practices\.md|missing-features\.md|feedback\.md#i';

    public function test_shipped_files_do_not_point_at_internal_planning_documents(): void
    {
        $root = dirname(__DIR__, 2);
        $matches = [];

        foreach (['src', 'config', 'routes', 'database', 'resources', 'README.md'] as $path) {
            $full = $root.'/'.$path;

            if (! file_exists($full)) {
                continue;
            }

            $files = is_file($full)
                ? [$full]
                : iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS)));

            foreach ($files as $file) {
                $file = (string) $file;

                if (! is_file($file) || ! preg_match('#\.(php|md|neon|json|blade\.php)$#', $file)) {
                    continue;
                }

                foreach (file($file) as $number => $line) {
                    if (preg_match(self::PATTERN, $line)) {
                        $matches[] = substr($file, strlen($root) + 1).':'.($number + 1).': '.trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $matches, "Rewrite these as self-contained sentences:\n".implode("\n", $matches));
    }
}
