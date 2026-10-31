<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use Aws\Command;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use MadeByClowd\Documentable\Exceptions\MultipartPartTooSmallException;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Services\S3ExceptionMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class S3ExceptionMapperTest extends TestCase
{
    public function test_it_maps_no_such_upload(): void
    {
        $command = new Command('AbortMultipartUpload');
        $s3Exception = new S3Exception('gone', $command, ['code' => 'NoSuchUpload']);

        $mapped = S3ExceptionMapper::mapMultipart($s3Exception, 'uploads/a.bin', 'upload-123');

        $this->assertInstanceOf(MultipartUploadNotFoundException::class, $mapped);
        $this->assertStringContainsString('upload-123', $mapped->getMessage());
        $this->assertSame($s3Exception, $mapped->getPrevious());
    }

    public function test_it_maps_entity_too_small(): void
    {
        $command = new Command('CompleteMultipartUpload');
        $s3Exception = new S3Exception('too small', $command, ['code' => 'EntityTooSmall']);

        $mapped = S3ExceptionMapper::mapMultipart($s3Exception, 'uploads/a.bin', 'upload-123');

        $this->assertInstanceOf(MultipartPartTooSmallException::class, $mapped);
        $this->assertStringContainsString('upload-123', $mapped->getMessage());
        $this->assertSame($s3Exception, $mapped->getPrevious());
    }

    public function test_it_leaves_unmapped_exceptions_as_s3_exception(): void
    {
        $command = new Command('CompleteMultipartUpload');
        $s3Exception = new S3Exception('denied', $command, ['code' => 'AccessDenied']);

        $mapped = S3ExceptionMapper::mapMultipart($s3Exception, 'uploads/a.bin', 'upload-123');

        $this->assertSame($s3Exception, $mapped);
    }

    private static function s3Error(?string $code, ?int $status): S3Exception
    {
        $context = ['code' => $code];

        if ($status !== null) {
            $context['response'] = new Response($status);
        }

        return new S3Exception('error', new Command('HeadObject'), $context);
    }

    /**
     * @return array<string, array{?string, ?int, bool}>
     */
    public static function goneCases(): array
    {
        return [
            'NotFound code' => ['NotFound', null, true],
            'NoSuchKey code' => ['NoSuchKey', null, true],
            'NoSuchUpload code' => ['NoSuchUpload', null, true],
            'bare 404 status' => ['SomethingElse', 404, true],
            'access denied' => ['AccessDenied', 403, false],
            'server error' => ['InternalError', 500, false],
            'no code, ok status' => [null, 200, false],
        ];
    }

    #[DataProvider('goneCases')]
    public function test_it_identifies_already_gone_errors(?string $code, ?int $status, bool $expected): void
    {
        $this->assertSame($expected, S3ExceptionMapper::isNotFoundOrGone(self::s3Error($code, $status)));
    }

    /**
     * @return array<string, array{?string, ?int, bool}>
     */
    public static function deniedCases(): array
    {
        return [
            'AccessDenied code' => ['AccessDenied', null, true],
            'NotImplemented code' => ['NotImplemented', null, true],
            'MethodNotAllowed code' => ['MethodNotAllowed', null, true],
            'bare 403 status' => ['Other', 403, true],
            'bare 405 status' => ['Other', 405, true],
            'bare 501 status' => ['Other', 501, true],
            'not found' => ['NoSuchKey', 404, false],
            'server error' => ['InternalError', 500, false],
            'neighbouring 402' => ['Other', 402, false],
            'neighbouring 406' => ['Other', 406, false],
            'neighbouring 502' => ['Other', 502, false],
        ];
    }

    #[DataProvider('deniedCases')]
    public function test_it_identifies_denied_or_unsupported_errors(?string $code, ?int $status, bool $expected): void
    {
        $this->assertSame($expected, S3ExceptionMapper::isAccessDeniedOrNotImplemented(self::s3Error($code, $status)));
    }

    public function test_only_s3_exceptions_are_classified(): void
    {
        $plain = new RuntimeException('AccessDenied');

        $this->assertFalse(S3ExceptionMapper::isNotFoundOrGone($plain));
        $this->assertFalse(S3ExceptionMapper::isAccessDeniedOrNotImplemented($plain));
    }
}
