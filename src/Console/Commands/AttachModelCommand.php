<?php

namespace MadeByClowd\Documentable\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Wires the Documentable trait into an existing model — the 2-line manual edit
 * (import + `use Documentable;`) a make:model-style generator can do instead,
 * matching the "do things the Laravel way" feel of the framework's own
 * generators (docs/feedbacks/feedback.md #4). Intentionally a conservative
 * string edit, not an AST rewrite — refuses rather than guessing whenever the
 * target file's shape isn't simple enough to edit safely.
 */
class AttachModelCommand extends Command
{
    protected $signature = 'documents:attach-model {model : The model class name, e.g. Invoice or Models/Invoice}';

    protected $description = 'Add the Documentable trait (and its import) to an existing Eloquent model.';

    protected const TRAIT_FQCN = 'MadeByClowd\Documentable\Traits\Documentable';

    protected const TRAIT_SHORT_NAME = 'Documentable';

    public function handle(): int
    {
        $qualified = $this->qualifyModel($this->argument('model'));
        $shortName = class_basename($qualified);
        $path = $this->modelPath($qualified);

        if (! file_exists($path)) {
            $this->error("Model file not found at {$path}.");

            return self::FAILURE;
        }

        $contents = file_get_contents($path);

        if (! preg_match('/^class\s+'.preg_quote($shortName, '/').'\b/m', $contents)) {
            $this->error("No `class {$shortName}` declaration found in {$path} — not touching it.");

            return self::FAILURE;
        }

        if ($this->alreadyUsesTrait($contents)) {
            $this->info("{$qualified} already uses the Documentable trait — nothing to do.");

            return self::SUCCESS;
        }

        $withTraitUse = $this->insertTraitUse($contents, $shortName);

        if ($withTraitUse === null) {
            return self::FAILURE;
        }

        $updated = $this->insertImport($withTraitUse);

        file_put_contents($path, $updated);

        $this->info("Added `use Documentable;` to {$qualified} ({$path}).");

        return self::SUCCESS;
    }

    /**
     * Same resolution Laravel's own make:model-family commands use: an
     * already-rooted name is left alone, otherwise it's placed under
     * app/Models (if that directory exists) or directly under the app
     * namespace — see Illuminate\Console\GeneratorCommand::qualifyModel().
     */
    protected function qualifyModel(string $model): string
    {
        $model = ltrim(str_replace('/', '\\', $model), '\\');

        $rootNamespace = $this->laravel->getNamespace();

        if (Str::startsWith($model, $rootNamespace)) {
            return $model;
        }

        return is_dir(app_path('Models'))
            ? $rootNamespace.'Models\\'.$model
            : $rootNamespace.$model;
    }

    protected function modelPath(string $qualified): string
    {
        $relative = Str::after($qualified, $this->laravel->getNamespace());

        return app_path(str_replace('\\', '/', $relative).'.php');
    }

    protected function alreadyUsesTrait(string $contents): bool
    {
        return (bool) preg_match(
            '/^\s*use\s+(?:\\\\?'.preg_quote(self::TRAIT_FQCN, '/').'|'.self::TRAIT_SHORT_NAME.')\s*;\s*$/m',
            $contents
        );
    }

    /**
     * Inserts `use Documentable;` inside the class body. Anchors on the first
     * `{` that opens the class declaration, then looks at the first non-blank
     * line after it:
     *   - nothing there yet -> insert as the new first line of the body.
     *   - a single simple `use Trait;` line (or a contiguous run of them) ->
     *     append our line right after that run.
     *   - anything else trait-use-shaped but not simple (comma list, `{...}`
     *     conflict-resolution block) -> refuse, this inserter can't safely
     *     disambiguate that shape.
     * Returns null (after printing why) when it refuses.
     */
    protected function insertTraitUse(string $contents, string $shortName): ?string
    {
        if (! preg_match('/^class\s+'.preg_quote($shortName, '/').'\b[^{]*\{\r?\n/m', $contents, $match, PREG_OFFSET_CAPTURE)) {
            $this->error("Could not find the opening `{` for `class {$shortName}` — not touching it.");

            return null;
        }

        $braceEnd = $match[0][1] + strlen($match[0][0]);
        $before = substr($contents, 0, $braceEnd);
        $after = substr($contents, $braceEnd);

        // explode/implode on "\n" alone (not "\r\n") deliberately — a line from
        // a CRLF file keeps its trailing "\r" as part of the element, and
        // implode("\n", ...) reconstructs the original line ending untouched.
        // Newly-inserted elements carry the same trailing "\r" (via $cr below)
        // so they don't end up as the one LF-only line in an otherwise-CRLF file.
        $lines = explode("\n", $after);
        $cr = str_contains($contents, "\r\n") ? "\r" : '';

        $simpleUsePattern = '/^\s*use\s+[A-Za-z_\\\\]+\s*;\s*\r?$/';
        $anyUsePattern = '/^\s*use\s+/';

        $insertAfterIndex = -1;

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                break;
            }

            if (preg_match($simpleUsePattern, $line)) {
                $insertAfterIndex = $index;

                continue;
            }

            if (preg_match($anyUsePattern, $line)) {
                $lineNumber = substr_count($before, "\n") + $index + 1;
                $this->error("Line {$lineNumber} has a trait `use` statement this conservative inserter can't safely disambiguate (comma list or conflict-resolution block) — add `use Documentable;` there yourself.");

                return null;
            }

            break;
        }

        $insertion = '    use '.self::TRAIT_SHORT_NAME.';'.$cr;

        if ($insertAfterIndex === -1) {
            array_splice($lines, 0, 0, [$insertion, $cr]);
        } else {
            array_splice($lines, $insertAfterIndex + 1, 0, [$insertion]);
        }

        return $before.implode("\n", $lines);
    }

    /**
     * Adds the import after the last top-level `use X;` statement (unindented
     * — distinguishes it from the indented trait-use lines inside the class
     * body), or right after `namespace X;` if there are no imports yet.
     */
    protected function insertImport(string $contents): string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        if (preg_match_all('/^use\s+[^\s(][^;]*;\r?\n/m', $contents, $matches, PREG_OFFSET_CAPTURE)) {
            $lastMatch = end($matches[0]);
            $insertAt = $lastMatch[1] + strlen($lastMatch[0]);

            return substr($contents, 0, $insertAt)
                .'use '.self::TRAIT_FQCN.';'.$eol
                .substr($contents, $insertAt);
        }

        if (preg_match('/^namespace\s+[^;]+;\r?\n/m', $contents, $match, PREG_OFFSET_CAPTURE)) {
            $insertAt = $match[0][1] + strlen($match[0][0]);

            return substr($contents, 0, $insertAt)
                .$eol.'use '.self::TRAIT_FQCN.';'.$eol
                .substr($contents, $insertAt);
        }

        return $contents;
    }
}
