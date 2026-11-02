# Testing your app

The package provides testing tools so application tests can exercise document workflows without real S3 buckets or external network calls:

```php
namespace Tests\Feature;

use App\Models\Invoice;
use Illuminate\Http\UploadedFile;
use MadeByClowd\Documentable\Documentable;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;
use Tests\TestCase;

class InvoiceUploadTest extends TestCase
{
    public function test_user_can_upload_invoice(): void
    {
        $fake = Documentable::fake();

        $type = DocumentType::factory()->create([
            'code' => 'INVOICE',
            'disk' => 's3',
        ]);
        $invoice = Invoice::factory()->create();

        $service = app(DocumentService::class);
        $document = $service->upload(
            UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf'),
            $type,
            $invoice
        );

        $fake->assertAttached(fn (Document $doc) => $doc->id === $document->id);
    }
}
```

`Documentable::fake()` swaps storage disks, the multipart driver, and package events. Validation, database writes, and versioning logic execute against the faked disk.

## Model factories

The package provides model factories for strict mode testing:
- `Document::factory()`: states `committed()` (default), `processing()`, `rejected()`, `latest()`, and `superseded()`.
- `DocumentType::factory()`: populates all required configuration columns.
- `StorageFile::factory()`: generates realistic SHA-256 hashes and sizes.
- `UploadSession::factory()`: states for lifecycle stages (`initiated()`, `uploaded()`, `attached()`, `expired()`, `aborted()`).

## Test assertions

`DocumentableFake` provides test assertions:

```php
$fake->assertUploaded(fn (UploadSession $session) => $session->id === $expectedId);
$fake->assertMultipartInitiated(fn (UploadSession $session) => $session->disk === 's3');
$fake->assertAttached(fn (Document $doc) => $doc->id === $document->id);
$fake->assertPurged(fn (Document $doc) => $doc->id === $document->id);
$fake->assertNothingUploaded();
```
