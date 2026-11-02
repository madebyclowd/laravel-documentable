<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Enums\DocumentStatus;
use MadeByClowd\Documentable\Http\Requests\UrlRequest;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentAccessLog;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use MadeByClowd\Documentable\Tests\Fixtures\ConfigurableAuthorizer;
use MadeByClowd\Documentable\Tests\Fixtures\TestModel;
use MadeByClowd\Documentable\Tests\TestCase;

class DownloadRoutesTest extends TestCase
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

    protected function createDocument(string $name = 'test.pdf'): Document
    {
        return $this->service->upload(
            UploadedFile::fake()->create($name, 1),
            $this->type,
            $this->owner
        );
    }

    public function test_download_route_redirects_with_no_store_and_attachment(): void
    {
        $doc = $this->createDocument('invoice.pdf');

        $response = $this->get("/documents/{$doc->id}/download");

        $response->assertStatus(302);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('Location'));
    }

    public function test_view_route_redirects_with_no_store_and_inline(): void
    {
        $doc = $this->createDocument('image.png');

        $response = $this->get("/documents/{$doc->id}/view");

        $response->assertStatus(302);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNotEmpty($response->headers->get('Location'));
    }

    public function test_download_and_view_routes_require_can_view(): void
    {
        $doc = $this->createDocument();

        $authorizer = ConfigurableAuthorizer::with([
            'canView' => fn () => false,
        ]);
        $this->app->instance(AuthorizesDocumentAccess::class, $authorizer);

        $this->get("/documents/{$doc->id}/download")->assertForbidden();
        $this->get("/documents/{$doc->id}/view")->assertForbidden();
    }

    public function test_download_and_view_routes_return_409_when_document_not_ready(): void
    {
        $doc = $this->createDocument();
        $doc->update(['status' => DocumentStatus::Processing]);

        $this->get("/documents/{$doc->id}/download")->assertStatus(409);
        $this->get("/documents/{$doc->id}/view")->assertStatus(409);

        $doc->update(['status' => DocumentStatus::Rejected]);

        $this->get("/documents/{$doc->id}/download")->assertStatus(409);
        $this->get("/documents/{$doc->id}/view")->assertStatus(409);
    }

    public function test_download_and_view_routes_return_404_for_missing_or_trashed_document(): void
    {
        $missingId = (string) str()->uuid();
        $this->get("/documents/{$missingId}/download")->assertNotFound();
        $this->get("/documents/{$missingId}/view")->assertNotFound();

        $doc = $this->createDocument();
        $doc->delete();

        $this->get("/documents/{$doc->id}/download")->assertNotFound();
        $this->get("/documents/{$doc->id}/view")->assertNotFound();
    }

    public function test_the_download_route_ignores_expires_in_minutes_and_uses_the_default_lifetime(): void
    {
        $this->travelTo(now()->startOfSecond());
        $expiry = $this->captureExpiry();
        $doc = $this->createDocument();

        $response = $this->get("/documents/{$doc->id}/download?expires_in_minutes=1000");

        $response->assertStatus(302);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(now()->addMinutes(UrlRequest::DEFAULT_TTL_MINUTES)->getTimestamp(), $expiry()->getTimestamp());
    }

    public function test_the_json_url_route_still_honours_expires_in_minutes(): void
    {
        $this->travelTo(now()->startOfSecond());
        $expiry = $this->captureExpiry();
        $doc = $this->createDocument();

        $this->getJson("/documents/{$doc->id}/url?expires_in_minutes=30")->assertOk();

        $this->assertSame(now()->addMinutes(30)->getTimestamp(), $expiry()->getTimestamp());
    }

    /**
     * @return \Closure(): \DateTimeInterface the expiry the disk was last asked to sign
     */
    private function captureExpiry(): \Closure
    {
        $captured = null;

        Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, \DateTimeInterface $expiry) use (&$captured) {
            $captured = $expiry;

            return 'https://signed.test/'.$path;
        });

        return function () use (&$captured) {
            return $captured ?? throw new \LogicException('Nothing was signed.');
        };
    }

    public function test_access_log_written_on_download_and_view_routes(): void
    {
        config()->set('documentable.audit.access_log', true);

        $doc = $this->createDocument('doc.pdf');

        $this->assertSame(0, DocumentAccessLog::count());

        $this->get("/documents/{$doc->id}/download")->assertStatus(302);
        $this->assertSame(1, DocumentAccessLog::count());
        $logDownload = DocumentAccessLog::first();
        $this->assertSame('download', $logDownload->action);
        $this->assertSame($doc->id, $logDownload->document_id);

        $this->get("/documents/{$doc->id}/view")->assertStatus(302);
        $this->assertSame(2, DocumentAccessLog::count());
        $logView = DocumentAccessLog::latest('id')->first();
        $this->assertSame('view', $logView->action);
        $this->assertSame($doc->id, $logView->document_id);
    }

    public function test_access_log_not_written_when_download_route_returns_409(): void
    {
        config()->set('documentable.audit.access_log', true);

        $doc = $this->createDocument('doc.pdf');
        $doc->update(['status' => DocumentStatus::Processing]);

        $this->get("/documents/{$doc->id}/download")->assertStatus(409);
        $this->get("/documents/{$doc->id}/view")->assertStatus(409);

        $this->assertSame(0, DocumentAccessLog::count());
    }
}
