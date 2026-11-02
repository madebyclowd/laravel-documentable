<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit\Validators;

use MadeByClowd\Documentable\Contracts\ValidatesUploadedFile;
use MadeByClowd\Documentable\Models\DocumentType;
use MadeByClowd\Documentable\Validators\DwgValidator;
use MadeByClowd\Documentable\Validators\DxfValidator;
use MadeByClowd\Documentable\Validators\GzipValidator;
use MadeByClowd\Documentable\Validators\OleDocumentValidator;
use MadeByClowd\Documentable\Validators\RarValidator;
use MadeByClowd\Documentable\Validators\SevenZipValidator;
use MadeByClowd\Documentable\Validators\ZipValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SignatureValidatorsTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string<ValidatesUploadedFile>, 1: string, 2: bool}>
     */
    public static function headers(): array
    {
        return [
            'dwg r2018' => [DwgValidator::class, "AC1032\0\0\0\0\0\0\0\0\0\0\0", true],
            'dwg r2000' => [DwgValidator::class, "AC1015\0\0\0\0\0", true],
            'dwg with a pdf header' => [DwgValidator::class, '%PDF-1.7', false],
            'dwg with a short AC prefix only' => [DwgValidator::class, 'AC10', false],
            'dwg with a non-numeric version' => [DwgValidator::class, 'AC10XY', false],
            'dwg empty' => [DwgValidator::class, '', false],
            'dxf ascii' => [DxfValidator::class, "  0\r\nSECTION\r\n  2\r\nHEADER\r\n", true],
            'dxf ascii after a comment group' => [DxfValidator::class, "999\nexported by a tool\n0\nSECTION\n", true],
            'dxf binary' => [DxfValidator::class, "AutoCAD Binary DXF\r\n\x1A\0", true],
            'dxf with plain prose' => [DxfValidator::class, 'Dear Sir, please find attached', false],
            'zip' => [ZipValidator::class, "PK\x03\x04\x14\0\0\0", true],
            'empty zip' => [ZipValidator::class, "PK\x05\x06\0\0\0\0", true],
            'zip with a gzip header' => [ZipValidator::class, "\x1F\x8B\x08\0", false],
            'ole document' => [OleDocumentValidator::class, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1\0\0", true],
            'ole with a zip header' => [OleDocumentValidator::class, "PK\x03\x04", false],
            'seven zip' => [SevenZipValidator::class, "7z\xBC\xAF\x27\x1C\0\x04", true],
            'seven zip with a rar header' => [SevenZipValidator::class, "Rar!\x1A\x07\0", false],
            'rar 4' => [RarValidator::class, "Rar!\x1A\x07\0", true],
            'rar 5' => [RarValidator::class, "Rar!\x1A\x07\x01\0", true],
            'rar with text' => [RarValidator::class, 'hello', false],
            'gzip' => [GzipValidator::class, "\x1F\x8B\x08\0\0\0\0\0", true],
            'gzip with a zip header' => [GzipValidator::class, "PK\x03\x04", false],
        ];
    }

    /**
     * @param  class-string<ValidatesUploadedFile>  $validator
     */
    #[DataProvider('headers')]
    public function test_it_accepts_the_real_header_and_names_the_problem_otherwise(string $validator, string $head, bool $valid): void
    {
        $reason = (new $validator)->validate(new DocumentType, 'drawing.bin', $head);

        $valid ? $this->assertNull($reason) : $this->assertIsString($reason);
        $valid || $this->assertNotSame('', $reason);
    }
}
