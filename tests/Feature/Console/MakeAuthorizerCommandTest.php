<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Feature\Console;

use App\Documentable\LoadedAuthorizer;
use App\Documentable\LoadedGates;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use MadeByClowd\Documentable\Contracts\AuthorizesDocumentAccess;
use MadeByClowd\Documentable\Defaults\GateDocumentAuthorizer;
use MadeByClowd\Documentable\Models\Document;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `documents:make-authorizer` scaffolds a starter authorizer that denies everything until its checks are filled in:
 * a `Gate::define` block for the default Gate-backed authorizer, or an `AuthorizesDocumentAccess` class.
 */
class MakeAuthorizerCommandTest extends TestCase
{
    protected string $fakeAppPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate generated files from Testbench's shared skeleton app/ directory —
        // this test writes real files, they must not land in vendor/.
        $this->fakeAppPath = sys_get_temp_dir().'/documentable-make-authorizer-test-'.uniqid();
        File::ensureDirectoryExists($this->fakeAppPath);
        $this->app->useAppPath($this->fakeAppPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fakeAppPath);

        parent::tearDown();
    }

    public function test_generates_the_gates_stub_by_default(): void
    {
        $this->artisan('documents:make-authorizer')->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/AppDocumentGates.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1);', $contents);
        $this->assertStringContainsString('namespace App\Documentable;', $contents);
        $this->assertStringContainsString('class AppDocumentGates', $contents);
        $this->assertStringNotContainsString('implements AuthorizesDocumentAccess', $contents);

        foreach (['documents.initiate-upload', 'documents.finalize', 'documents.attach', 'documents.view', 'documents.delete'] as $ability) {
            $this->assertStringContainsString("Gate::define('{$ability}'", $contents);
        }
    }

    public function test_the_gates_stub_explains_how_to_wire_it_up(): void
    {
        $this->artisan('documents:make-authorizer --style=gates')
            ->expectsOutputToContain('\App\Documentable\AppDocumentGates::register();')
            ->expectsOutputToContain('GateDocumentAuthorizer::class')
            ->assertExitCode(0);
    }

    public function test_generates_the_class_stub_with_the_default_name(): void
    {
        $this->artisan('documents:make-authorizer --style=class')
            ->expectsOutputToContain('\App\Documentable\AppDocumentAuthorizer::class')
            ->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/AppDocumentAuthorizer.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('declare(strict_types=1);', $contents);
        $this->assertStringContainsString('namespace App\Documentable;', $contents);
        $this->assertStringContainsString('class AppDocumentAuthorizer implements AuthorizesDocumentAccess', $contents);

        foreach (['canInitiateUpload(', 'canFinalize(', 'canAttach(', 'canView(', 'canDelete(', 'canViewMany('] as $method) {
            $this->assertStringContainsString("public function {$method}", $contents);
        }

        $this->assertStringNotContainsString('canUpload', $contents);
        $this->assertStringNotContainsString('=== null', $contents);
    }

    public function test_generates_a_stub_with_a_custom_name(): void
    {
        $this->artisan('documents:make-authorizer', ['name' => 'CustomAuthorizer', '--style' => 'class'])->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/CustomAuthorizer.php';
        $this->assertFileExists($path);
        $this->assertStringContainsString('class CustomAuthorizer implements AuthorizesDocumentAccess', file_get_contents($path));
    }

    public function test_rejects_an_unknown_style(): void
    {
        $this->artisan('documents:make-authorizer --style=bogus')
            ->expectsOutputToContain("Invalid --style value: 'bogus'. Expected gates or class.")
            ->assertExitCode(1);

        $this->assertDirectoryDoesNotExist($this->fakeAppPath.'/Documentable');
    }

    public function test_refuses_to_overwrite_existing_file(): void
    {
        $this->artisan('documents:make-authorizer')->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/AppDocumentGates.php';
        $originalContents = file_get_contents($path);

        $this->artisan('documents:make-authorizer')->assertExitCode(1);

        $this->assertSame($originalContents, file_get_contents($path));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function styles(): array
    {
        return ['gates' => ['gates'], 'class' => ['class']];
    }

    #[DataProvider('styles')]
    public function test_generated_stub_is_valid_php_and_denies_everything(string $style): void
    {
        $this->artisan('documents:make-authorizer', ['name' => 'Scaffolded', '--style' => $style])->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/Scaffolded.php';
        exec('php -l '.escapeshellarg($path), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));

        // Every check the stub ships answers false until the app replaces it.
        $contents = (string) file_get_contents($path);
        $this->assertSame(0, preg_match('/return true;/', $contents));
        $this->assertGreaterThanOrEqual(5, preg_match_all('/return false;/', $contents));
    }

    public function test_the_class_stub_denies_when_loaded(): void
    {
        $this->artisan('documents:make-authorizer', ['name' => 'LoadedAuthorizer', '--style' => 'class'])->assertExitCode(0);
        require_once $this->fakeAppPath.'/Documentable/LoadedAuthorizer.php';

        $authorizer = new LoadedAuthorizer;
        $type = new DocumentType(['code' => 'X']);

        $this->assertInstanceOf(AuthorizesDocumentAccess::class, $authorizer);
        $this->assertFalse($authorizer->canInitiateUpload(null, $type));
        $this->assertCount(0, $authorizer->canViewMany(null, new Collection([new Document])));
    }

    public function test_the_gates_stub_denies_when_registered(): void
    {
        $this->artisan('documents:make-authorizer', ['name' => 'LoadedGates'])->assertExitCode(0);
        require_once $this->fakeAppPath.'/Documentable/LoadedGates.php';

        LoadedGates::register();
        $user = (new User)->forceFill(['id' => 1]);

        $authorizer = new GateDocumentAuthorizer;
        $this->assertFalse($authorizer->canInitiateUpload($user, new DocumentType(['code' => 'X'])));
        $this->assertFalse($authorizer->canView($user, new Document));
        $this->assertFalse($authorizer->canDelete($user, new Document));
    }
}
