<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use MadeByClowd\Documentable\Tests\TestCase;
use MadeByClowd\Documentable\Upgrade\CodeScanner;

class ReadmeTest extends TestCase
{
    private string $root;

    private string $readmePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->readmePath = $this->root.'/README.md';
    }

    public function test_readme_headings_follow_target_structure_order(): void
    {
        $content = (string) file_get_contents($this->readmePath);
        $lines = explode("\n", $content);

        $h2Headings = [];
        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+)$/', trim($line), $match)) {
                $h2Headings[] = trim($match[1]);
            }
        }

        $expectedH2Headings = [
            'Quick start (5 minutes)',
            'Concepts',
            'Authorization',
            'Upload flows and the client',
            'Reading documents',
            'Versions and retention',
            'Operations',
            'Configuration reference',
            'Security',
            'Testing your app',
            'AI agent context (Laravel Boost)',
            'Upgrading',
            'License',
        ];

        $this->assertSame(
            $expectedH2Headings,
            $h2Headings,
            'README.md top-level (H2) headings must match the target structure and order exactly.'
        );
    }

    public function test_all_artisan_commands_in_readme_are_registered(): void
    {
        $content = (string) file_get_contents($this->readmePath);

        // Match backticks `php artisan <command>` or code lines `php artisan <command>`
        $commands = [];

        preg_match_all('/`php artisan\s+([a-z0-9:-]+)/i', $content, $backtickMatches);
        foreach ($backtickMatches[1] as $cmd) {
            $commands[] = $cmd;
        }

        preg_match_all('/^\s*php artisan\s+([a-z0-9:-]+)/m', $content, $lineMatches);
        foreach ($lineMatches[1] as $cmd) {
            $commands[] = $cmd;
        }

        $commands = array_unique($commands);
        $this->assertNotEmpty($commands, 'README.md must contain php artisan commands.');

        $artisan = Artisan::all();
        $externalCommands = ['boost:install', 'boost:update'];

        foreach ($commands as $cmd) {
            if (in_array($cmd, $externalCommands, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                $cmd,
                $artisan,
                "Artisan command `{$cmd}` named in README.md must be a registered command."
            );
        }
    }

    public function test_all_documentable_config_keys_in_backticks_exist(): void
    {
        $content = (string) file_get_contents($this->readmePath);
        $config = require $this->root.'/config/documentable.php';

        preg_match_all('/`documentable\.([a-z0-9_.]+)`/i', $content, $matches);
        $this->assertNotEmpty($matches[1], 'README.md must contain `documentable.<key>` references.');

        foreach (array_unique($matches[1]) as $keyPath) {
            $exists = Arr::has($config, $keyPath) || array_key_exists($keyPath, $config);

            $this->assertTrue(
                $exists,
                "Config key `documentable.{$keyPath}` in README.md does not exist in config/documentable.php."
            );
        }
    }

    public function test_all_internal_anchor_links_and_relative_file_links_resolve(): void
    {
        $content = (string) file_get_contents($this->readmePath);

        // Internal anchor links: [text](#anchor)
        preg_match_all('/\]\((#[a-z0-9_-]+)\)/i', $content, $anchorMatches);
        $internalLinks = array_unique($anchorMatches[1]);

        $headingSlugs = $this->extractHeadingSlugs($content);

        foreach ($internalLinks as $link) {
            $this->assertContains(
                $link,
                $headingSlugs,
                "Internal anchor `{$link}` does not resolve to any heading in README.md."
            );
        }

        // Relative file links: [text](path/to/file)
        preg_match_all('/\[[^\]]+\]\((?!https?:\/\/|mailto:|#)([^)#\s]+)(?:#[^\)]*)?\)/', $content, $fileMatches);
        $relativeLinks = array_unique($fileMatches[1]);

        foreach ($relativeLinks as $relPath) {
            $target = $this->root.'/'.ltrim($relPath, '/');
            $this->assertFileExists(
                $target,
                "Relative file link `{$relPath}` in README.md does not exist."
            );
        }
    }

    public function test_no_stale_strings_or_process_words_appear(): void
    {
        $content = (string) file_get_contents($this->readmePath);

        $stalePatterns = [
            '/PermissiveDocumentAuthorizer/' => 'stale authorizer "PermissiveDocumentAuthorizer"',
            '/\bcanUpload\s*\(/i' => 'stale authorizer method "canUpload()"',
            '/pending_ttl_hours/' => 'stale config key "pending_ttl_hours"',
            '/\buploadDetached\b/' => 'removed method "uploadDetached"',
            '/\breassociateDocument\b/' => 'removed method "reassociateDocument"',
            '/->commit\s*\(\)/' => 'removed method "commit()" on document',
            '/status.*[\'"]pending[\'"]|[\'"]pending[\'"].*status|\bDocumentStatus::Pending\b/i' => 'removed "pending" document status',
            '/use_native_checksum/' => 'removed config key "use_native_checksum"',
            '/max_versions_to_keep/' => 'renamed config key "max_versions_to_keep"',
            '/allow_guest_user_id/' => 'removed config key "allow_guest_user_id"',
            '/enforce_path_prefix/' => 'removed config key "enforce_path_prefix"',
        ];

        foreach ($stalePatterns as $pattern => $label) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $content,
                "README.md must not contain {$label}."
            );
        }

        $bannedWords = [
            '/\bimpl\b/i' => 'internal process word "impl"',
            '/\bphase\b/i' => 'internal process word "phase"',
            '/\badr(?:-\d+)?\b/i' => 'internal process word "ADR"',
            '/\bvault\b/i' => 'internal process word "vault"',
            '/\bagy\b/i' => 'internal process word "agy"',
            '/\bclaude\b/i' => 'internal process word "Claude"',
            '/owner decided/i' => 'phrase "owner decided"',
            '/—/' => 'em dash (—)',
            '/\brobust\b/i' => 'filler "robust"',
            '/\bseamless\b/i' => 'filler "seamless"',
            '/\bleverage\b/i' => 'filler "leverage"',
            '/\bpowerful\b/i' => 'filler "powerful"',
        ];

        foreach ($bannedWords as $pattern => $label) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $content,
                "README.md must not contain {$label}."
            );
        }

        // Banned decorative emoji
        $this->assertDoesNotMatchRegularExpression(
            '/[\x{1F300}-\x{1F5FF}\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u',
            $content,
            'README.md must not contain decorative emoji.'
        );
    }

    public function test_readme_names_version_lifecycle_keys_and_command(): void
    {
        $content = (string) file_get_contents($this->readmePath);

        $this->assertStringContainsString('on_delete_latest', $content);
        $this->assertStringContainsString('versions_kept', $content);
        $this->assertStringContainsString('versions_retain_days', $content);
        $this->assertStringContainsString('retention.hold', $content);
        $this->assertStringContainsString('documents:prune-versions', $content);
    }

    public function test_code_samples_do_not_trigger_scanner_rules(): void
    {
        $content = (string) file_get_contents($this->readmePath);

        // Extract PHP code snippets
        preg_match_all('/```php\n(.*?)\n```/s', $content, $phpMatches);
        $this->assertNotEmpty($phpMatches[1], 'README.md must contain PHP code snippets.');

        $tmpDir = sys_get_temp_dir().'/readme_scanner_test_'.uniqid();
        mkdir($tmpDir.'/app', 0777, true);
        mkdir($tmpDir.'/config', 0777, true);

        try {
            foreach ($phpMatches[1] as $idx => $snippet) {
                // Determine whether snippet is config or app code
                $targetDir = str_contains($snippet, "'disk' =>") || str_contains($snippet, "'types' =>")
                    ? $tmpDir.'/config'
                    : $tmpDir.'/app';

                file_put_contents("{$targetDir}/Snippet{$idx}.php", "<?php\n\n{$snippet}\n");
            }

            $scanner = new CodeScanner;
            $findings = $scanner->scan($tmpDir);

            $messages = array_map(fn ($f) => "{$f->file}:{$f->line} [{$f->rule}] {$f->message}", $findings);
            $this->assertEmpty($findings, "Code samples in README triggered upgrade scanner findings:\n".implode("\n", $messages));
        } finally {
            $this->deleteDirectory($tmpDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = (array) scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * @return list<string>
     */
    private function extractHeadingSlugs(string $content): array
    {
        $headingSlugs = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^#{1,6}\s+(.+)$/', $trimmed, $headingMatch)) {
                $headingText = $headingMatch[1];
                if (preg_match('/<a\s+(?:id|name)=["\']([^"\']+)["\']\s*>\s*<\/a>/i', $headingText, $anchorMatch)) {
                    $headingSlugs[] = '#'.$anchorMatch[1];
                    $headingText = trim(str_replace($anchorMatch[0], '', $headingText));
                }
                $headingSlugs[] = '#'.$this->slugify($headingText);
            }

            if (preg_match_all('/<a\s+(?:id|name)=["\']([^"\']+)["\']\s*>/i', $trimmed, $anchorMatches)) {
                foreach ($anchorMatches[1] as $anchorId) {
                    $headingSlugs[] = '#'.$anchorId;
                }
            }
        }

        return array_values(array_unique($headingSlugs));
    }

    private function slugify(string $text): string
    {
        // Strip HTML anchors
        $text = (string) preg_replace('/<a\s+[^>]*>.*?<\/a>/i', '', $text);
        // Strip markdown links [label](url) -> label
        $text = (string) preg_replace('/\[([^\]]+)\]\([^\)]+\)/', '$1', $text);
        // Strip code backticks, bold, italic
        $text = str_replace(['`', '*', '_'], '', $text);
        // Lowercase
        $text = mb_strtolower($text, 'UTF-8');
        // Replace non-alphanumeric (except hyphen/underscore) with space
        $text = (string) preg_replace('/[^\p{L}\p{Nd}\s_-]+/u', '', $text);
        // Replace whitespace with hyphens
        $text = (string) preg_replace('/\s+/', '-', trim($text));

        return $text;
    }
}
