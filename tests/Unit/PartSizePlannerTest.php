<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use InvalidArgumentException;
use MadeByClowd\Documentable\Services\PartSizePlanner;
use PHPUnit\Framework\TestCase;

class PartSizePlannerTest extends TestCase
{
    private const ONE_MIB = 1024 * 1024;

    private const FIVE_MIB = 5 * 1024 * 1024;

    private const FIVE_GIB = 5 * 1024 * 1024 * 1024;

    public function test_it_rejects_non_positive_sizes(): void
    {
        $planner = new PartSizePlanner;

        $this->expectException(InvalidArgumentException::class);
        $planner->partSize(0);
    }

    public function test_it_rejects_negative_sizes(): void
    {
        $planner = new PartSizePlanner;

        $this->expectException(InvalidArgumentException::class);
        $planner->partSize(-100);
    }

    public function test_it_defaults_to_five_mib_for_small_and_moderate_files(): void
    {
        $planner = new PartSizePlanner;

        $this->assertSame(self::FIVE_MIB, $planner->partSize(100));
        $this->assertSame(self::FIVE_MIB, $planner->partSize(self::FIVE_MIB));
        $this->assertSame(self::FIVE_MIB, $planner->partSize(10 * self::ONE_MIB));
        $this->assertSame(self::FIVE_MIB, $planner->partSize(1000 * self::ONE_MIB));
        $this->assertSame(self::FIVE_MIB, $planner->partSize(10000 * self::FIVE_MIB));
    }

    public function test_it_rounds_up_to_whole_mib_for_larger_files(): void
    {
        $planner = new PartSizePlanner;

        // 100 GiB = 100 * 1024 * 1024 * 1024 = 107374182400 bytes.
        // 107374182400 / 10000 = 10737418.24 bytes (~10.24 MiB) -> rounded UP to 11 MiB.
        $totalBytes = 100 * 1024 * self::ONE_MIB;
        $expected = 11 * self::ONE_MIB;

        $this->assertSame($expected, $planner->partSize($totalBytes));
        $this->assertSame(9310, $planner->partCount($totalBytes));
        $this->assertLessThanOrEqual(10000, $planner->partCount($totalBytes));
    }

    public function test_it_caps_part_size_at_five_gib(): void
    {
        $planner = new PartSizePlanner;

        // 50,000 GiB object -> 50,000 / 10,000 = 5 GiB.
        $totalBytes = 50000 * self::FIVE_GIB / 5;

        $this->assertSame(self::FIVE_GIB, $planner->partSize((int) $totalBytes));
        $this->assertSame(10000, $planner->partCount((int) $totalBytes));
    }

    public function test_it_rejects_sizes_exceeding_ten_thousand_parts_at_the_cap(): void
    {
        $planner = new PartSizePlanner;

        // Object larger than 10000 * 5 GiB (~50 TiB)
        $excessiveSize = (10000 * self::FIVE_GIB) + 1;

        $this->expectException(InvalidArgumentException::class);
        $planner->partSize($excessiveSize);
    }

    public function test_part_count_calculates_correct_number_of_parts(): void
    {
        $planner = new PartSizePlanner;

        $this->assertSame(1, $planner->partCount(100));
        $this->assertSame(1, $planner->partCount(self::FIVE_MIB));
        $this->assertSame(2, $planner->partCount(self::FIVE_MIB + 1));
        $this->assertSame(2, $planner->partCount(10 * self::ONE_MIB));
        $this->assertSame(3, $planner->partCount(11 * self::ONE_MIB));
    }
}
