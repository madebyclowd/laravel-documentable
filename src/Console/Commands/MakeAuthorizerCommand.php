<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;

/**
 * Scaffolds a starter AuthorizesDocumentAccess implementation in app/Documentable,
 * matching the make:policy convention. Default (PermissiveDocumentAuthorizer)
 * allows everything (docs/feedbacks/feedback.md #2) — this command exists so
 * "bind your own implementation" isn't the last a consumer hears of it.
 */
class MakeAuthorizerCommand extends Command
{
    protected $signature = 'documents:make-authorizer {name=AppDocumentAuthorizer}';

    protected $description = 'Scaffold a starter AuthorizesDocumentAccess implementation in app/Documentable.';

    public function handle(): int
    {
        $name = $this->argument('name');
        $path = app_path('Documentable/'.$name.'.php');

        if (file_exists($path)) {
            $this->error("{$path} already exists — not overwriting.");

            return self::FAILURE;
        }

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $this->stub($name));

        $this->info("Created {$path}.");
        $this->line('');
        $this->line('Wire it up in config/documentable.php:');
        $this->line("    'authorization' => ['resolver' => \\App\\Documentable\\{$name}::class],");

        return self::SUCCESS;
    }

    protected function stub(string $name): string
    {
        return <<<PHP
        <?php

        namespace App\Documentable;

        use Illuminate\Contracts\Auth\Authenticatable;
        use Illuminate\Database\Eloquent\Model;
        use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
        use MadeByClowd\Documentable\Models\Document;
        use MadeByClowd\Documentable\Models\DocumentType;

        class {$name} implements AuthorizesDocumentAccess
        {
            public function canUpload(?Authenticatable \$user, DocumentType \$type, ?Model \$documentable): bool
            {
                // TODO: replace with a real ownership/role check, e.g.:
                // return \$documentable instanceof \\App\\Models\\Project && \$documentable->owner()->is(\$user);
                return false;
            }

            public function canView(?Authenticatable \$user, Document \$document): bool
            {
                // TODO: replace with a real ownership/role check, e.g.:
                // return \$document->documentable instanceof \\App\\Models\\Project && \$document->documentable->owner()->is(\$user);
                return false;
            }

            public function canDelete(?Authenticatable \$user, Document \$document): bool
            {
                // TODO: replace with a real ownership/role check.
                return false;
            }
        }

        PHP;
    }
}
