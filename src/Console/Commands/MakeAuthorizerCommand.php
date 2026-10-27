<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;

/**
 * Scaffolds a starter authorizer in app/Documentable, matching the make:policy convention. Nothing is
 * allowed until the generated checks are filled in, so a freshly scaffolded app denies every request.
 * `--style=gates` writes a `Gate::define` block for the default Gate-backed authorizer; `--style=class`
 * writes an `AuthorizesDocumentAccess` implementation.
 */
class MakeAuthorizerCommand extends Command
{
    public const STYLES = ['gates', 'class'];

    protected $signature = 'documents:make-authorizer
        {name? : Class name. Defaults to AppDocumentGates for --style=gates and AppDocumentAuthorizer for --style=class.}
        {--style=gates : gates (a Gate::define block for the default authorizer) or class (an AuthorizesDocumentAccess implementation).}';

    protected $description = 'Scaffold a starter document authorizer in app/Documentable.';

    public function handle(): int
    {
        $style = $this->option('style');

        if (! is_string($style) || ! in_array($style, self::STYLES, true)) {
            $this->error('Invalid --style value: '.(is_string($style) ? "'{$style}'" : 'none').'. Expected '.implode(' or ', self::STYLES).'.');

            return self::FAILURE;
        }

        $name = $this->argument('name');
        $name = is_string($name) && $name !== '' ? $name : ($style === 'gates' ? 'AppDocumentGates' : 'AppDocumentAuthorizer');
        $path = app_path('Documentable/'.$name.'.php');

        if (file_exists($path)) {
            $this->error("{$path} already exists — not overwriting.");

            return self::FAILURE;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $style === 'gates' ? $this->gatesStub($name) : $this->classStub($name));

        $this->info("Created {$path}.");
        $this->line('');

        if ($style === 'gates') {
            $this->line('Register the gates from your AppServiceProvider::boot():');
            $this->line("    \\App\\Documentable\\{$name}::register();");
            $this->line('');
            $this->line('Production also needs the authorizer named in config/documentable.php, even to keep the Gate-backed default:');
            $this->line("    'authorization' => ['resolver' => \\MadeByClowd\\Documentable\\Defaults\\GateDocumentAuthorizer::class],");
        } else {
            $this->line('Wire it up in config/documentable.php:');
            $this->line("    'authorization' => ['resolver' => \\App\\Documentable\\{$name}::class],");
        }

        return self::SUCCESS;
    }

    protected function gatesStub(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Documentable;

        use Illuminate\Contracts\Auth\Authenticatable;
        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Support\Facades\Gate;
        use MadeByClowd\Documentable\Models\Document;
        use MadeByClowd\Documentable\Models\DocumentType;
        use MadeByClowd\Documentable\Models\UploadSession;

        /**
         * Every ability starts denied. Return true where the user should be allowed. A closure whose first
         * parameter is not nullable is never called for a guest, so guests are denied without extra code.
         */
        class {$name}
        {
            public static function register(): void
            {
                // Start an upload of this type: presign, multipart initiate, a direct upload, and the type list.
                Gate::define('documents.initiate-upload', function (Authenticatable \$user, DocumentType \$type): bool {
                    // TODO: e.g. return \$user->can('upload-'.\$type->code);
                    return false;
                });

                // Drive an upload session: part URLs, list parts, status, abort and finalize.
                Gate::define('documents.finalize', function (Authenticatable \$user, UploadSession \$session): bool {
                    // TODO: usually the user the session was started for, e.g.
                    // return (string) \$user->getAuthIdentifier() === \$session->user_id;
                    return false;
                });

                // Attach an upload to its owner. \$source is the uploaded session, or an unsaved Document that
                // carries only document_type_id for a direct POST /documents upload.
                Gate::define('documents.attach', function (Authenticatable \$user, UploadSession|Document \$source, Model \$owner): bool {
                    // TODO: e.g. return \$owner instanceof \\App\\Models\\Project && \$owner->owner()->is(\$user);
                    return false;
                });

                Gate::define('documents.view', function (Authenticatable \$user, Document \$document): bool {
                    // TODO: e.g. return \$document->documentable instanceof \\App\\Models\\Project
                    //     && \$document->documentable->owner()->is(\$user);
                    return false;
                });

                Gate::define('documents.delete', function (Authenticatable \$user, Document \$document): bool {
                    // TODO: replace with a real ownership/role check.
                    return false;
                });
            }
        }

        PHP;
    }

    protected function classStub(string $name): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace App\Documentable;

        use Illuminate\Contracts\Auth\Authenticatable;
        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Support\Collection;
        use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
        use MadeByClowd\Documentable\Models\Document;
        use MadeByClowd\Documentable\Models\DocumentType;
        use MadeByClowd\Documentable\Models\UploadSession;

        class {$name} implements AuthorizesDocumentAccess
        {
            public function canInitiateUpload(?Authenticatable \$user, DocumentType \$type): bool
            {
                // TODO: e.g. return \$user?->can('upload-'.\$type->code) === true;
                return false;
            }

            public function canFinalize(?Authenticatable \$user, UploadSession \$session): bool
            {
                // TODO: usually the user the session was started for, e.g.
                // return \$user !== null && (string) \$user->getAuthIdentifier() === \$session->user_id;
                return false;
            }

            public function canAttach(?Authenticatable \$user, UploadSession|Document \$source, Model \$owner): bool
            {
                // TODO: e.g. return \$owner instanceof \\App\\Models\\Project && \$owner->owner()->is(\$user);
                return false;
            }

            public function canView(?Authenticatable \$user, Document \$document): bool
            {
                // TODO: e.g. return \$document->documentable instanceof \\App\\Models\\Project
                //     && \$document->documentable->owner()->is(\$user);
                return false;
            }

            public function canDelete(?Authenticatable \$user, Document \$document): bool
            {
                // TODO: replace with a real ownership/role check.
                return false;
            }

            /**
             * Asked once per page of a listing. Answer with one query where you can; this falls back to canView().
             */
            public function canViewMany(?Authenticatable \$user, Collection \$documents): Collection
            {
                return \$documents->filter(fn (Document \$document) => \$this->canView(\$user, \$document))->values();
            }
        }

        PHP;
    }
}
