# Laravel Documentable: file uploads, versions and S3 storage for Eloquent models

[![Latest Version on Packagist](https://img.shields.io/packagist/v/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![Total Downloads](https://img.shields.io/packagist/dt/madebyclowd/laravel-documentable.svg?style=flat-square)](https://packagist.org/packages/madebyclowd/laravel-documentable)
[![run-tests](https://img.shields.io/github/actions/workflow/status/madebyclowd/laravel-documentable/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/madebyclowd/laravel-documentable/actions/workflows/run-tests.yml)
[![security](https://img.shields.io/github/actions/workflow/status/madebyclowd/laravel-documentable/security.yml?branch=main&label=security&style=flat-square)](https://github.com/madebyclowd/laravel-documentable/actions/workflows/security.yml)
[![codecov](https://img.shields.io/codecov/c/github/madebyclowd/laravel-documentable?style=flat-square)](https://codecov.io/gh/madebyclowd/laravel-documentable)
[![License](https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square)](LICENSE)

**Let users upload files to your Laravel app, store them in S3, R2 or MinIO, and keep every version, without sending a single byte through your PHP server.**

Laravel Documentable attaches documents (PDFs, scans, contracts, photos) to any Eloquent model. The browser uploads straight to your bucket with a presigned URL. Big files go in parts and can resume. The same file is stored once. Old versions are kept, then cleaned up on a schedule you choose.

```bash
composer require madebyclowd/laravel-documentable
```

```php
$invoice->documentOfType('invoice-pdf')->first();   // the latest PDF of this invoice
$service->getUrl($document, now()->addMinutes(5));  // a private link that expires in 5 minutes
```

## Small on purpose

A plain `Storage::put()` is small, but then you write the hard parts yourself: direct uploads, big files, versions, duplicates, cleanup, and "who may see this file?". A full media library covers a lot, but it brings its own admin screens, image processing and database tables you did not ask for.

Documentable sits in between. It does one job, **business documents that belong to a model**, and does it completely. Nothing else.

| You get | In plain words |
|---|---|
| **Direct to bucket** | Files never touch your web server. No timeouts, no memory limit, no `upload_max_filesize` fights. |
| **Big files that resume** | A 2 GB upload that drops at 90% continues where it stopped. |
| **Same file, stored once** | A file with the same SHA-256 is saved one time. A user cannot "claim" someone else's file by guessing its hash: they must prove they hold the bytes. |
| **Versions and retention** | Upload a new passport scan and the old one stays. Keep the last 5 versions, or 90 days. |
| **Real file checks** | The file type is read from the bytes, not the file name. A `.exe` renamed to `.pdf` is refused. |
| **Safe by default** | Every action goes through your authorizer. In production the app refuses to boot without one. |
| **Self-cleaning** | Half-finished uploads and orphan files in the bucket are found and removed. |
| **Any S3-compatible bucket** | AWS S3, Cloudflare R2, MinIO, DigitalOcean Spaces. |
| **No admin UI, no lock-in** | You keep your own screens. Plain Eloquent models and a small HTTP API. |

It also ships an optional TypeScript upload helper (hashing, retry, resume, progress) so your Vue, React or Inertia front end does not need to rebuild any of this.

### Is it for you?

**Use it** when users upload documents that must be kept, versioned, protected and audited: invoices, contracts, IDs, certificates, medical or legal files, permit applications.

**Skip it** when you only need a profile picture or a one-off file field, or when you need image resizing, galleries or a media browser. A simple `store()` call or a media library fits those better.

## Quick start (5 minutes)

Five small steps: install, say what kind of file you accept, mark your model, upload, read it back. You do not need to know S3 first.

### 1. Install the package

```bash
composer require madebyclowd/laravel-documentable
php artisan documents:install
```

The installer publishes the configuration and database migrations, offers to run `php artisan migrate`, and sets your owner key type, application shape, multipart ETag strategy, and catalog mode.

In production, booting with package routes mounted requires an authorizer. Scaffold an authorizer class:

```bash
php artisan documents:make-authorizer AppDocumentAuthorizer --style=class
```

Then configure it in `config/documentable.php`:

```php
'authorization' => [
    'resolver' => \App\Documentable\AppDocumentAuthorizer::class,
],
```

To use Laravel Gates instead, set the resolver to `MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer::class`, or run `php artisan documents:make-authorizer --style=gates` and define the gate abilities in your service provider. Leaving the resolver unset uses the same Gate-backed authorizer, which denies every ability you have not defined, and production boot throws until you set it.

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
