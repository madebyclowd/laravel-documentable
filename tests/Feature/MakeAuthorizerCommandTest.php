<?php

namespace MadeByClowd\Documentable\Tests\Feature;

use Illuminate\Support\Facades\File;
use MadeByClowd\Documentable\Tests\TestCase;

/**
 * Regression coverage for docs/feedbacks/feedback.md #2 — `documents:make-authorizer`
 * scaffolds a starter AuthorizesDocumentAccess implementation instead of leaving the
 * default PermissiveDocumentAuthorizer as the only thing a consumer discovers.
 * See docs/implementations/v2.0.0/phase-10-authorizer-scaffolding-command.md.
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

    public function test_generates_authorizer_stub_with_default_name(): void
    {
        $this->artisan('documents:make-authorizer')->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/AppDocumentAuthorizer.php';
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $this->assertStringContainsString('namespace App\Documentable;', $contents);
        $this->assertStringContainsString('class AppDocumentAuthorizer implements AuthorizesDocumentAccess', $contents);
        $this->assertStringContainsString('public function canUpload(', $contents);
        $this->assertStringContainsString('public function canView(', $contents);
        $this->assertStringContainsString('public function canDelete(', $contents);
    }

    public function test_generates_authorizer_stub_with_custom_name(): void
    {
        $this->artisan('documents:make-authorizer', ['name' => 'CustomAuthorizer'])->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/CustomAuthorizer.php';
        $this->assertFileExists($path);
        $this->assertStringContainsString('class CustomAuthorizer implements AuthorizesDocumentAccess', file_get_contents($path));
    }

    public function test_refuses_to_overwrite_existing_file(): void
    {
        $this->artisan('documents:make-authorizer')->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/AppDocumentAuthorizer.php';
        $originalContents = file_get_contents($path);

        $this->artisan('documents:make-authorizer')->assertExitCode(1);

        $this->assertSame($originalContents, file_get_contents($path));
    }

    public function test_generated_stub_is_valid_php(): void
    {
        $this->artisan('documents:make-authorizer')->assertExitCode(0);

        $path = $this->fakeAppPath.'/Documentable/AppDocumentAuthorizer.php';
        exec('php -l '.escapeshellarg($path), $output, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $output));
    }
}
