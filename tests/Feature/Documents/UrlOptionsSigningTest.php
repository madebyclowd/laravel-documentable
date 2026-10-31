<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Documents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use MadeByClowd\Documentable\Config\DocumentableConfig;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Exceptions\DocumentNotReady;
use MadeByClowd\Documentable\Http\Requests\UrlRequest;
use MadeByClowd\Documentable\Models\DocumentAccessLog;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\ForceAttachmentUrlModifier;
use MadeByClowd\Documentable\Tests\Fixtures\InlineHtmlUrlModifier;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\ValueObjects\UrlOptions;

class UrlOptionsSigningTest extends TestCase
{
    use RefreshDatabase;

    protected DocumentService $service;

    protected DocumentType $type;

    protected TestModel $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        $this->service = $this->app->make(DocumentService::class);
        $this->type = DocumentType::create([
            'code' => 'DOC',
            'name' => 'Doc',
            'max_size_mb' => 10,
            'disk' => 's3',
            'path_prefix' => 'docs',
            'allows_multiple' => true,
            'requires_versioning' => false,
        ]);
        $this->owner = TestModel::create(['name' => 'Owner']);
    }

    public function test_old_get_url_signature_still_works(): void
    {
        $doc = $this->service->upload(UploadedFile::fake()->create('report.pdf', 1), $this->type, $this->owner);

        $url = $this->service->getUrl($doc, now()->addMinutes(15), 'attachment');

        $this->assertNotEmpty($url);
    }

    public function test_get_url_with_url_options(): void
    {
        $doc = $this->service->upload(UploadedFile::fake()->create('image.png', 1), $this->type, $this->owner);

        $options = new UrlOptions(
            ttl: now()->addMinutes(20),
            disposition: 'inline',
            filename: 'custom-name.png',
            contentType: 'image/png'
        );

        $url = $this->service->getUrl($doc, $options);

        $this->assertNotEmpty($url);
    }

    public function test_the_modifier_changes_what_gets_signed(): void
    {
        $this->useModifier(ForceAttachmentUrlModifier::class);
        config()->set('documentable.audit.access_log', true);

        $doc = $this->service->upload(UploadedFile::fake()->create('original.pdf', 1), $this->type, $this->owner);

        $this->service->getUrl($doc, new UrlOptions(disposition: 'inline'));

        $this->assertSame('download', DocumentAccessLog::sole()->action, 'The modifier forced attachment, so the access is a download.');
    }

    public function test_a_modifier_cannot_smuggle_html_into_an_inline_url(): void
    {
        $this->useModifier(InlineHtmlUrlModifier::class);

        $doc = $this->service->upload(UploadedFile::fake()->create('doc.pdf', 1), $this->type, $this->owner);

        $this->expectException(InvalidArgumentException::class);
        $this->service->getUrl($doc, new UrlOptions(disposition: 'inline'));
    }

    private function useModifier(string $class): void
    {
        config()->set('documentable.url.modifier', $class);
        $this->app->forgetInstance(DocumentableConfig::class);
    }

    /**
     * Records what the disk is asked to sign: the expiry and the response overrides.
     *
     * @return object{expiry: ?\DateTimeInterface, options: array<string, mixed>}
     */
    private function recordSignedRequest(): object
    {
        $captured = (object) ['expiry' => null, 'options' => []];

        Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, \DateTimeInterface $expiry, array $options) use ($captured) {
            $captured->expiry = $expiry;
            $captured->options = $options;

            return 'https://signed.test/'.$path;
        });

        return $captured;
    }

    public function test_the_url_expires_at_the_requested_ttl_and_never_after_the_configured_maximum(): void
    {
        $this->travelTo(now()->startOfSecond());
        $signed = $this->recordSignedRequest();
        $doc = $this->service->upload(UploadedFile::fake()->create('a.pdf', 1), $this->type, $this->owner);

        $this->service->getUrl($doc, new UrlOptions(ttl: now()->addMinutes(5)));
        $this->assertSame(now()->addMinutes(5)->getTimestamp(), $signed->expiry->getTimestamp());

        $this->service->getUrl($doc, new UrlOptions(ttl: new \DateInterval('PT7M')));
        $this->assertSame(now()->addMinutes(7)->getTimestamp(), $signed->expiry->getTimestamp());

        config()->set('documentable.url.max_ttl_minutes', 60);
        $this->service->getUrl($doc, new UrlOptions(ttl: now()->addHours(5)));
        $this->assertSame(now()->addMinutes(60)->getTimestamp(), $signed->expiry->getTimestamp(), 'Capped at the maximum, not one minute either side.');
    }

    public function test_the_url_uses_the_default_ttl_when_none_is_given(): void
    {
        $this->travelTo(now()->startOfSecond());
        $signed = $this->recordSignedRequest();
        $doc = $this->service->upload(UploadedFile::fake()->create('a.pdf', 1), $this->type, $this->owner);

        $this->service->getUrl($doc, new UrlOptions);

        $this->assertSame(now()->addMinutes(UrlRequest::DEFAULT_TTL_MINUTES)->getTimestamp(), $signed->expiry->getTimestamp());
    }

    public function test_the_download_name_and_content_type_reach_the_signed_request(): void
    {
        $signed = $this->recordSignedRequest();
        $doc = $this->service->upload(UploadedFile::fake()->create('original.pdf', 1), $this->type, $this->owner);

        $this->service->getUrl($doc, new UrlOptions(disposition: 'attachment', filename: 'Q3 report.pdf', contentType: 'application/pdf'));

        $this->assertSame('attachment; filename="Q3 report.pdf"', $signed->options['ResponseContentDisposition']);
        $this->assertSame('application/pdf', $signed->options['ResponseContentType']);

        $this->service->getUrl($doc, new UrlOptions);
        $this->assertSame('inline; filename="original.pdf"', $signed->options['ResponseContentDisposition'], 'Without an override the client filename is used.');
        $this->assertArrayNotHasKey('ResponseContentType', $signed->options);
    }

    public function test_access_log_written_for_get_url_and_temporary_url_when_enabled(): void
    {
        config()->set('documentable.audit.access_log', true);

        $doc = $this->service->upload(UploadedFile::fake()->create('doc.pdf', 1), $this->type, $this->owner);

        $this->assertSame(0, DocumentAccessLog::count());

        $this->service->getUrl($doc, new UrlOptions(disposition: 'attachment'));
        $this->assertSame(1, DocumentAccessLog::count());
        $log1 = DocumentAccessLog::first();
        $this->assertSame($doc->id, $log1->document_id);
        $this->assertSame('download', $log1->action);

        $doc->temporaryUrl();
        $this->assertSame(2, DocumentAccessLog::count());
        $log2 = DocumentAccessLog::latest('id')->first();
        $this->assertSame('view', $log2->action);
    }

    public function test_access_log_not_written_when_document_not_ready(): void
    {
        config()->set('documentable.audit.access_log', true);

        $doc = $this->service->upload(UploadedFile::fake()->create('doc.pdf', 1), $this->type, $this->owner);
        $doc->update(['status' => DocumentStatus::Processing]);

        try {
            $this->service->getUrl($doc, new UrlOptions);
            $this->fail('Expected DocumentNotReady exception.');
        } catch (DocumentNotReady) {
            // expected
        }

        $this->assertSame(0, DocumentAccessLog::count());
    }
}
