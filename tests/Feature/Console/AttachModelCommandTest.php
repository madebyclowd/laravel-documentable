<?php

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/feedback.md #4 — adding `use Documentable;`
 * to a model was a manual 2-line edit; documents:attach-model automates it. See
 * docs/implementations/v2.0.0/phase-13-attach-model-command.md.
 */
class AttachModelCommandTest extends TestCase
{
    protected string $fakeAppPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Same reasoning as MakeAuthorizerCommandTest — Testbench's app_path()
        // resolves into a shared vendor/ skeleton, this command writes real
        // files, isolate them.
        $this->fakeAppPath = sys_get_temp_dir().'/documentable-attach-model-test-'.uniqid();
        File::ensureDirectoryExists($this->fakeAppPath.'/Models');

        // Application::getNamespace() reverse-resolves the namespace by matching
        // path() against composer.json's psr-4 mapping — that only works before
        // useAppPath() diverges path() from the real app/ directory. Call it once
        // here to warm its internal cache before overriding.
        $this->app->getNamespace();
        $this->app->useAppPath($this->fakeAppPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fakeAppPath);

        parent::tearDown();
    }

    protected function writeModel(string $name, string $contents): string
    {
        $path = $this->fakeAppPath.'/Models/'.$name.'.php';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_adds_import_and_trait_use_to_plain_model(): void
    {
        $path = $this->writeModel('Invoice', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;

        class Invoice extends Model
        {
            protected $guarded = [];
        }

        PHP);

        $this->artisan('documents:attach-model', ['model' => 'Invoice'])->assertExitCode(0);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('use MadeByClowd\Documentable\Traits\Documentable;', $contents);
        $this->assertStringContainsString('    use Documentable;', $contents);

        exec('php -l '.escapeshellarg($path), $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_appends_after_existing_trait_use_without_creating_second_block(): void
    {
        $path = $this->writeModel('Invoice', <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;
        use Illuminate\Database\Eloquent\SoftDeletes;

        class Invoice extends Model
        {
            use SoftDeletes;

            protected $guarded = [];
        }

        PHP);

        $this->artisan('documents:attach-model', ['model' => 'Invoice'])->assertExitCode(0);

        // Normalize line endings before the structural check below — this test's own
        // heredoc fixture is subject to the same git-checkout CRLF/LF normalization as
        // any other tracked file, so a literal "\n" match must not depend on which style
        // this checkout happens to use. Line-ending *consistency* of the actual written
        // file (no mixed \n/\r\n) is what src/Console/Commands/AttachModelCommand.php is
        // responsible for, and is why this line-ending-agnostic assertion is still a
        // meaningful regression check, not a weakened one.
        $contents = str_replace("\r\n", "\n", file_get_contents($path));
        $this->assertStringContainsString("use SoftDeletes;\n    use Documentable;", $contents);
        $this->assertSame(1, substr_count($contents, 'use Documentable;'));

        exec('php -l '.escapeshellarg($path), $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_noop_when_trait_already_used(): void
    {
        $original = <<<'PHP'
        <?php

        namespace App\Models;

        use Illuminate\Database\Eloquent\Model;
        use MadeByClowd\Documentable\Traits\Documentable;

        class Invoice extends Model
        {
            use Documentable;

            protected $guarded = [];
        }

        PHP;
        $path = $this->writeModel('Invoice', $original);

        $this->artisan('documents:attach-model', ['model' => 'Invoice'])->assertExitCode(0);

        $this->assertSame($original, file_get_contents($path));
    }

    public function test_adds_import_as_first_use_statement_when_none_exist(): void
    {
        $path = $this->writeModel('Invoice', <<<'PHP'
        <?php

        namespace App\Models;

        class Invoice extends \Illuminate\Database\Eloquent\Model
        {
            protected $guarded = [];
        }

        PHP);

        $this->artisan('documents:attach-model', ['model' => 'Invoice'])->assertExitCode(0);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('use MadeByClowd\Documentable\Traits\Documentable;', $contents);
        $this->assertStringContainsString('    use Documentable;', $contents);

        exec('php -l '.escapeshellarg($path), $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_unresolvable_model_errors_without_touching_anything(): void
    {
        $this->artisan('documents:attach-model', ['model' => 'DoesNotExist'])->assertExitCode(1);

        $this->assertFileDoesNotExist($this->fakeAppPath.'/Models/DoesNotExist.php');
    }
}
