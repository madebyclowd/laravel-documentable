# Security

## Morph map enforcement

All upload and attach requests resolve `documentable_type` through `Relation::getMorphedModel()`. Enforce morph maps in your application service provider:

```php
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;

Relation::enforceMorphMap([
    'invoice' => Invoice::class,
    'user' => User::class,
]);
```

The package rejects model classes that have no morph map entry, unless you list them in `documentable.security.allowed_documentable_types`. Clients should send the morph map alias (`invoice`), never the internal class FQCN.

## Production authorization requirement

In production, booting with package routes mounted and `documentable.authorization.resolver` set to `null` throws `MadeByClowd\Documentable\Exceptions\InsecureConfiguration`. You must bind an authorizer class implementing `AuthorizesDocumentAccess` or configure `GateDocumentAuthorizer`.

## Scanner error policy

When an antivirus scanner returns `ScanResult::Error` (for example, during scanner service downtime), the default policy `documentable.security.scan_error_policy = 'reject'` refuses the upload and removes the storage file. If file availability is preferred over strict scanning during outages, set the policy to `'allow'`.

## Upload sessions and storage security

Every upload belongs to an `UploadSession` record that the server manages. The server generates the storage keys and never shows them to clients. Clients refer to an upload only by its session ID, so they cannot tamper with a storage key or traverse a path.
