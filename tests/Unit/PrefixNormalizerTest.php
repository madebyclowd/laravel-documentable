<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use InvalidArgumentException;
use MadeByClowd\Documentable\Services\PrefixNormalizer;
use PHPUnit\Framework\TestCase;

class PrefixNormalizerTest extends TestCase
{
    public function test_it_normalizes_prefixes_to_exactly_one_trailing_slash(): void
    {
        $this->assertSame('invoices/', PrefixNormalizer::normalize('invoices'));
        $this->assertSame('invoices/', PrefixNormalizer::normalize('invoices/'));
        $this->assertSame('invoices/', PrefixNormalizer::normalize('/invoices/'));
        $this->assertSame('invoices/2026/', PrefixNormalizer::normalize('invoices/2026'));
        $this->assertSame('invoices/2026/', PrefixNormalizer::normalize('/invoices/2026/'));
    }

    public function test_it_rejects_empty_or_root_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PrefixNormalizer::normalize('');
    }

    public function test_it_rejects_slash_only_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PrefixNormalizer::normalize('/');
    }

    public function test_it_rejects_multiple_slashes_only_prefix(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PrefixNormalizer::normalize('///');
    }
}
