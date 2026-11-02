# Authorization

The package delegates authorization decisions to implementations of `MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess`.

## Authorizer methods

Every method receives the authenticated user (or `null` for unauthenticated requests) and the subject being acted on:

- `canInitiateUpload(?Authenticatable $user, DocumentType $type): bool`: decides whether the user may start an upload session for this document type. Also filters the types returned by `GET /documents/types`. No owner exists yet at this stage, so decide on the user and the type only; rules that depend on the owner belong in `canAttach`.
- `canFinalize(?Authenticatable $user, UploadSession $session): bool`: decides whether the user may finalize or check status on an in-flight upload session.
- `canAttach(?Authenticatable $user, UploadSession|Document $source, Model $owner): bool`: decides whether the user may attach an upload session or document to the specified owner model.
- `canView(?Authenticatable $user, Document $document): bool`: decides whether the user may view or download the document.
- `canDelete(?Authenticatable $user, Document $document): bool`: decides whether the user may soft-delete the document.
- `canViewMany(?Authenticatable $user, Collection $documents): Collection`: filters a collection of documents for bulk listing queries (`GET /documents`), returning only visible items.

## Gate document authorizer

The default authorizer is `MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer`. It routes each authorization check to Laravel's Gate:

- `documents.initiate-upload` (`DocumentType $type`)
- `documents.finalize` (`UploadSession $session`)
- `documents.attach` (`UploadSession|Document $source`, `Model $owner`)
- `documents.view` (`Document $document`)
- `documents.delete` (`Document $document`)

If a gate ability is undefined, `GateDocumentAuthorizer` falls back to matching policy methods on registered model policies (`initiateUpload`, `finalize`, `attach`, `view`, `delete`). If no policy or ability handles the check, access is denied.

## Production boot check

In production (`app()->isProduction()`), booting with package routes enabled and `documentable.authorization.resolver` set to `null` throws `MadeByClowd\Documentable\Exceptions\InsecureConfiguration`. You must configure an explicit authorizer before deploying.

## Scaffold an authorizer

Scaffold an authorizer using the Artisan command:

```bash
# Scaffold a dedicated class:
php artisan documents:make-authorizer AppDocumentAuthorizer --style=class

# Or scaffold a Gate definition provider:
php artisan documents:make-authorizer AppDocumentGates --style=gates
```

Configure your authorizer in `config/documentable.php`:

```php
'authorization' => [
    'resolver' => \App\Documentable\AppDocumentAuthorizer::class,
],
```
