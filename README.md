# Laravel Documentable: file uploads, versions and S3 storage for Eloquent models

[![Latest Version on Packagist](https://img.shields.io/packagist/v/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![Total Downloads](https://img.shields.io/packagist/dt/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![run-tests](https://img.shields.io/github/actions/workflow/status/madebyclowd/laravel-documentable/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/madebyclowd/laravel-documentable/actions/workflows/run-tests.yml)
[![security](https://img.shields.io/github/actions/workflow/status/madebyclowd/laravel-documentable/security.yml?branch=main&label=security&style=flat-square)](https://github.com/madebyclowd/laravel-documentable/actions/workflows/security.yml)
[![codecov](https://img.shields.io/codecov/c/github/madebyclowd/laravel-documentable?style=flat-square)](https://codecov.io/gh/madebyclowd/laravel-documentable)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

**Let users upload files to your Laravel app, store them in S3, R2 or MinIO, and keep every version, without sending a single byte through your PHP server.**

Laravel Documentable attaches documents (PDFs, scans, contracts, photos) to any Eloquent model. The browser uploads straight to your bucket with a presigned URL. Big files upload in parts and can resume. A file you upload twice is stored once. Old versions are kept, then deleted on the schedule you choose.

```bash
composer require madebyclowd/laravel-documentable
```

```php
$invoice->documentOfType('invoice-pdf')->first();   // the latest PDF of this invoice
$service->getUrl($document, now()->addMinutes(5));  // a private link that expires in 5 minutes
```

## Small on purpose

`Storage::put()` is small, but you then write direct uploads, big-file handling, versions, duplicate detection, cleanup and access checks yourself. A full media library covers more, and adds admin screens, image processing and tables you may not want.

Documentable is the middle option. It handles one thing, business documents that belong to a model, and leaves everything else to you.

| Feature | What it does |
|---|---|
| Direct to bucket | The browser sends the file to the bucket. PHP's `upload_max_filesize`, `post_max_size` and request timeout do not apply to it. |
| Resumable big files | A 2 GB upload that drops at 90% continues from the last finished part. |
| Same file, stored once | Files with the same SHA-256 share one stored copy. A user cannot claim a file by guessing its hash, because the server asks for proof that they hold the bytes. |
| Versions and retention | A new passport scan keeps the old one. You set how many versions or how many days to keep. |
| File checks | The server reads the file type from the bytes, not the file name, so a `.exe` renamed to `.pdf` is refused. |
| Authorizer required | Every action asks your authorizer. In production the app does not boot without one. |
| Cleanup | Commands delete half-finished uploads and bucket files that no document points to. You schedule them. |
| Any S3-compatible bucket | AWS S3, Cloudflare R2, MinIO and DigitalOcean Spaces. |
| No admin UI | You build your own screens on plain Eloquent models and a small HTTP API. |

The package also ships an optional TypeScript upload helper that hashes files, retries, resumes and reports progress, so your Vue, React or Inertia front end does not need its own version of that code.

### Is it for you?

**Use it** when users upload documents that must be kept, versioned, protected and audited: invoices, contracts, IDs, certificates, medical or legal files, permit applications.

**Skip it** when you only need a profile picture or a one-off file field, or when you need image resizing, galleries or a media browser. A simple `store()` call or a media library fits those better.

## Quick start (5 minutes)

Six steps: install, say which files you accept, mark your model, upload, read it back, and set access rules before production. You do not need to know S3 first.

### 1. Install the package

```bash
composer require madebyclowd/laravel-documentable
php artisan documents:install
```

The installer publishes the configuration and database migrations, offers to run `php artisan migrate`, and sets your owner key type, application shape, multipart ETag strategy, and catalog mode.

### 2. Define a document type

Add a document type definition in `config/documentable.php`:

```php
'types' => [
    'invoice-pdf' => [
        'name' => 'Invoice PDF',
        'max_size_mb' => 10,
        'allowed_mimes' => ['application/pdf'],
        'disk' => 's3',
        'path_prefix' => 'invoices',
        'requires_versioning' => true,
        'allows_multiple' => false,
    ],
],
```

Sync the type configuration into your database:

```bash
php artisan documents:sync-types
```

### 3. Attach the trait to your model

Add `use Documentable;` to your owning model:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use MadeByClowd\Documentable\Traits\Documentable;

class Invoice extends Model
{
    use Documentable;
}
```

Or run the helper command:

```bash
php artisan documents:attach-model Invoice
```

### 4. Upload a file

Upload a file from a controller action using `DocumentService`:

```php
namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Services\DocumentService;

class InvoiceDocumentController extends Controller
{
    public function store(Request $request, Invoice $invoice, DocumentService $service): JsonResponse
    {
        $type = DocumentType::where('code', 'invoice-pdf')->firstOrFail();

        $document = $service->upload(
            $request->file('file'),
            $type,
            $invoice
        );

        return response()->json($document, 201);
    }
}
```

### 5. Read the document back

```php
// Retrieve the latest document for the invoice:
$document = $invoice->documentOfType('invoice-pdf')->first();

// Generate a temporary download URL valid for 5 minutes:
$url = $service->getUrl($document, now()->addMinutes(5));

// Or read the raw file contents safely:
$contents = $service->readContents($document);
```

### 6. Before production: say who may do what

The steps above work on your machine without any access rules, because `DocumentService` is plain PHP. The HTTP routes are different. They ask an authorizer, and the default denies everything. In production the app also refuses to boot until you set one.

Generate one and fill in your own checks:

```bash
php artisan documents:make-authorizer AppDocumentAuthorizer --style=class
```

```php
// config/documentable.php
'authorization' => [
    'resolver' => \App\Documentable\AppDocumentAuthorizer::class,
],
```

Prefer Laravel Gates? Run `php artisan documents:make-authorizer --style=gates` and define the abilities in a service provider. Details are in [Authorization](docs/authorization.md).


To upload from client applications directly to storage over HTTP, see [Upload flows](docs/uploads.md).

## Documentation

The README is the short version. Everything else is in [`docs/`](docs/):

| Read this | When you want to |
|---|---|
| [Concepts](docs/concepts.md) | Learn the words: document type, session, attach, version, status. |
| [Authorization](docs/authorization.md) | Decide who may upload, see and delete. |
| [Upload flows](docs/uploads.md) | Use presigned PUT, multipart, dedup, or upload before the owner exists. |
| [Browser client](docs/browser-client.md) | Use the TypeScript helper in Vue, React or Inertia. |
| [Reading documents](docs/reading-documents.md) | Show, download, stream and list files. |
| [Versions and retention](docs/versions-and-retention.md) | Keep, restore, promote and prune versions. |
| [Operations](docs/operations.md) | Run the doctor, cleanup, CORS, proxies and the deploy checklist. |
| [Configuration](docs/configuration.md) | Look up every config key. |
| [Security](docs/security.md) | Check the safety rules before production. |
| [Testing your app](docs/testing.md) | Fake storage, factories and assertions. |

## AI agent context (Laravel Boost)

The package ships guidelines and skills for [Laravel Boost](https://github.com/laravel/boost): `resources/boost/guidelines/laravel-documentable.blade.php` (conventions and pitfalls) and `resources/boost/skills/laravel-documentable/SKILL.md` (the detailed reference). Boost finds them in `vendor/` on its own. Boost copies them into your project, so run `php artisan boost:update` after each package update.

## Upgrading from 2.x

Follow [`UPGRADE.md`](UPGRADE.md). It lists the breaking changes and the exact command order.

## License

The MIT License (MIT). Please see the [LICENSE](LICENSE) file for more information.
