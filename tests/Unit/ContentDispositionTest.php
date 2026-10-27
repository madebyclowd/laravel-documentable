<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use MadeByClowd\Documentable\Services\UrlSigner;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6266: an ASCII `filename` for old clients, and `filename*` (RFC 5987, UTF-8, percent-encoded) when the
 * real name has anything else, so a browser saves "résumé.pdf" as written.
 */
class ContentDispositionTest extends TestCase
{
    public function test_an_ascii_name_needs_only_the_plain_parameter(): void
    {
        $this->assertSame('inline; filename="report.pdf"', UrlSigner::contentDisposition('inline', 'report.pdf'));
        $this->assertSame('attachment; filename="report.pdf"', UrlSigner::contentDisposition('attachment', 'report.pdf'));
    }

    public function test_a_non_ascii_name_adds_the_extended_parameter_and_an_ascii_fallback(): void
    {
        $this->assertSame(
            "inline; filename=\"r_sum_.pdf\"; filename*=UTF-8''r%C3%A9sum%C3%A9.pdf",
            UrlSigner::contentDisposition('inline', 'résumé.pdf')
        );
    }

    public function test_cjk_names_fall_back_to_underscores_and_survive_in_the_extended_parameter(): void
    {
        $header = UrlSigner::contentDisposition('attachment', '日本.pdf');

        $this->assertStringContainsString('filename="__.pdf"', $header);
        $this->assertStringContainsString("filename*=UTF-8''%E6%97%A5%E6%9C%AC.pdf", $header);
    }

    public function test_quotes_backslashes_and_control_characters_cannot_break_out_of_the_header(): void
    {
        $header = UrlSigner::contentDisposition('inline', "a\"b\\c\r\nSet-Cookie: x.pdf");

        $this->assertStringNotContainsString("\r", $header);
        $this->assertStringNotContainsString("\n", $header);
        $this->assertSame(1, preg_match('/^inline; filename="[^"\\\\]*"/', $header));
    }

    public function test_a_name_that_sanitizes_to_nothing_gets_a_generic_one(): void
    {
        $this->assertSame('inline; filename="download"', UrlSigner::contentDisposition('inline', "\"\r\n"));
    }

    public function test_the_extended_parameter_encodes_the_characters_that_delimit_it(): void
    {
        $header = UrlSigner::contentDisposition('inline', "it's é*.pdf");

        $this->assertStringContainsString("filename*=UTF-8''it%27s%20%C3%A9%2A.pdf", $header);
    }

    public function test_sanitizing_still_strips_what_it_always_did(): void
    {
        $this->assertSame('ab.pdf', UrlSigner::sanitizeHeaderFilename("a\tb\"\x7F.pdf"));
        $this->assertSame('', UrlSigner::sanitizeHeaderFilename("\"\r\n"));
        $this->assertSame('a_b.pdf', UrlSigner::sanitizeHeaderFilename('a\\b.pdf'));
        $this->assertSame('r_sum_.pdf', UrlSigner::sanitizeHeaderFilename('résumé.pdf'));
    }
}
