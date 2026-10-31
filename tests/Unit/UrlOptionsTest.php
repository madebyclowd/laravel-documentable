<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use DateInterval;
use InvalidArgumentException;
use MadeByClowd\Documentable\Http\Requests\UrlRequest;
use MadeByClowd\Documentable\Services\UrlSigner;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

class UrlOptionsTest extends TestCase
{
    public function test_default_values(): void
    {
        $options = new UrlOptions;

        $this->assertNull($options->ttl);
        $this->assertSame('inline', $options->disposition);
        $this->assertNull($options->filename);
        $this->assertNull($options->contentType);
    }

    public function test_disposition_validation(): void
    {
        new UrlOptions(disposition: 'inline');
        new UrlOptions(disposition: 'attachment');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Disposition must be 'inline' or 'attachment'");

        new UrlOptions(disposition: 'invalid');
    }

    public function test_inline_content_type_allowlist_permits_safe_types(): void
    {
        $safe = [
            'image/png',
            'image/jpeg',
            'image/webp',
            'image/gif',
            'application/pdf',
        ];

        foreach ($safe as $mime) {
            $options = new UrlOptions(disposition: 'inline', contentType: $mime);
            $this->assertSame($mime, $options->contentType);
        }
    }

    public function test_inline_content_type_allowlist_rejects_html_and_svg(): void
    {
        $dangerous = [
            'text/html',
            'image/svg+xml',
            'application/xhtml+xml',
            'text/javascript',
        ];

        foreach ($dangerous as $mime) {
            try {
                new UrlOptions(disposition: 'inline', contentType: $mime);
                $this->fail("Expected InvalidArgumentException for {$mime} with inline disposition.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not permitted for inline disposition', $e->getMessage());
            }
        }
    }

    public function test_attachment_disposition_allows_any_content_type(): void
    {
        $options = new UrlOptions(disposition: 'attachment', contentType: 'text/html');
        $this->assertSame('text/html', $options->contentType);

        $svgOptions = new UrlOptions(disposition: 'attachment', contentType: 'image/svg+xml');
        $this->assertSame('image/svg+xml', $svgOptions->contentType);
    }

    public function test_ttl_is_clamped_to_max_ttl_minutes_for_datetime(): void
    {
        config()->set('documentable.url.max_ttl_minutes', 60);

        $farFuture = now()->addHours(5);
        $options = new UrlOptions(ttl: $farFuture);

        $this->assertLessThanOrEqual(now()->addMinutes(60)->timestamp, $options->expiration()->getTimestamp());
    }

    public function test_ttl_is_clamped_to_max_ttl_minutes_for_date_interval(): void
    {
        config()->set('documentable.url.max_ttl_minutes', 60);

        $interval = new DateInterval('PT2H'); // 2 hours
        $options = new UrlOptions(ttl: $interval);

        $this->assertInstanceOf(DateInterval::class, $options->ttl);
        $expiration = $options->expiration();
        $this->assertLessThanOrEqual(now()->addMinutes(60)->timestamp, $expiration->getTimestamp());
    }

    public function test_expiration_uses_default_when_ttl_is_null(): void
    {
        $options = new UrlOptions;
        $expiration = $options->expiration();

        $expected = now()->addMinutes(UrlRequest::DEFAULT_TTL_MINUTES)->timestamp;
        $this->assertEqualsWithDelta($expected, $expiration->getTimestamp(), 2);
    }

    public function test_filename_sanitization_in_content_disposition(): void
    {
        $header = UrlSigner::contentDisposition('attachment', "unsafe\"\r\nheader.pdf");
        $this->assertSame('attachment; filename="unsafeheader.pdf"', $header);

        $utfHeader = UrlSigner::contentDisposition('inline', 'dokumen résumé.pdf');
        $this->assertStringContainsString('filename="dokumen r_sum_.pdf"', $utfHeader);
        $this->assertStringContainsString("filename*=UTF-8''dokumen%20r%C3%A9sum%C3%A9.pdf", $utfHeader);
    }

    public function test_the_inline_content_type_is_compared_without_case_whitespace_or_parameters(): void
    {
        $options = new UrlOptions(disposition: 'inline', contentType: '  IMAGE/PNG ; charset=binary');

        $this->assertSame('  IMAGE/PNG ; charset=binary', $options->contentType);
    }
}
