<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use MadeByClowd\Documentable\Services\FilenameNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FilenameNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function names(): array
    {
        return [
            'a plain name is unchanged' => ['report 2026 (final).pdf', 'report 2026 (final).pdf'],
            'unicode is kept' => ['résumé 日本語.pdf', 'résumé 日本語.pdf'],
            'a unix path keeps its last segment' => ['../../etc/passwd', 'passwd'],
            'a windows path keeps its last segment' => ['C:\\Users\\me\\plan.dwg', 'plan.dwg'],
            'a mixed path' => ['a/b\\c/plan.dwg', 'plan.dwg'],
            'control characters are removed' => ["a\0b\tc\nd\x7F.pdf", 'abcd.pdf'],
            'runs of spaces collapse' => ['a    b  .pdf', 'a b .pdf'],
            'leading dots are removed so the name cannot be hidden' => ['..hidden.txt', 'hidden.txt'],
            'trailing dots and spaces are removed' => ['plan.dwg. . ', 'plan.dwg'],
            'only separators leave the fallback' => ['///', 'file'],
            'an empty name leaves the fallback' => ['', 'file'],
            'only dots leave the fallback' => ['....', 'file'],
            'invalid utf-8 is scrubbed' => ["bad\xC3\x28name.pdf", 'bad(name.pdf'],
        ];
    }

    #[DataProvider('names')]
    public function test_it_produces_a_safe_name(string $raw, string $expected): void
    {
        $this->assertSame($expected, FilenameNormalizer::normalize($raw));
    }

    public function test_a_long_name_is_capped_in_bytes_and_keeps_its_extension(): void
    {
        $name = str_repeat('a', 400).'.pdf';

        $normalized = FilenameNormalizer::normalize($name);

        $this->assertSame(255, strlen($normalized));
        $this->assertStringEndsWith('.pdf', $normalized);
    }

    public function test_the_cap_never_splits_a_multibyte_character(): void
    {
        $normalized = FilenameNormalizer::normalize(str_repeat('é', 200).'.pdf');

        $this->assertLessThanOrEqual(255, strlen($normalized));
        $this->assertTrue(mb_check_encoding($normalized, 'UTF-8'));
        $this->assertStringEndsWith('.pdf', $normalized);
    }

    public function test_a_name_whose_extension_alone_exceeds_the_cap_is_still_capped(): void
    {
        $this->assertLessThanOrEqual(255, strlen(FilenameNormalizer::normalize('a.'.str_repeat('x', 400))));
    }

    public function test_normalizing_twice_changes_nothing(): void
    {
        foreach (self::names() as [$raw]) {
            $once = FilenameNormalizer::normalize($raw);

            $this->assertSame($once, FilenameNormalizer::normalize($once));
        }
    }
}
