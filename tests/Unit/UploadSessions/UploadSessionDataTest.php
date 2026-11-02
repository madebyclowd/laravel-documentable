<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit\UploadSessions;

use InvalidArgumentException;
use MadeByClowd\Documentable\ValueObjects\UploadSessionData;
use PHPUnit\Framework\TestCase;

class UploadSessionDataTest extends TestCase
{
    public function test_it_maps_to_the_declared_columns(): void
    {
        $data = new UploadSessionData(sizeBytes: 2048, mimeType: 'application/pdf', sha256: str_repeat('a', 64));

        $this->assertSame([
            'declared_size' => 2048,
            'declared_mime' => 'application/pdf',
            'declared_hash' => str_repeat('a', 64),
        ], $data->toColumns());
    }

    public function test_everything_is_optional(): void
    {
        $this->assertSame(
            ['declared_size' => null, 'declared_mime' => null, 'declared_hash' => null],
            (new UploadSessionData)->toColumns()
        );
    }

    public function test_a_hash_is_normalised_to_lower_case(): void
    {
        $this->assertSame(str_repeat('ab', 32), (new UploadSessionData(sha256: strtoupper(str_repeat('ab', 32))))->sha256);
    }

    public function test_a_negative_size_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UploadSessionData(sizeBytes: -1);
    }

    public function test_a_hash_that_is_not_64_hex_characters_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new UploadSessionData(sha256: 'not-a-hash');
    }
}
