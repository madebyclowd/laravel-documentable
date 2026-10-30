<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Tests\Unit;

use Aws\Command;
use Aws\S3\Exception\S3Exception;
use MadeByClowd\Documentable\Exceptions\MultipartPartTooSmallException;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use MadeByClowd\Documentable\Services\S3ExceptionMapper;
use PHPUnit\Framework\TestCase;

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

    public function test_it_identifies_already_gone_errors(): void
    {
        $command = new Command('HeadObject');

        $this->assertTrue(S3ExceptionMapper::isNotFoundOrGone(new S3Exception('404', $command, ['code' => 'NotFound', 'status_code' => 404])));
        $this->assertTrue(S3ExceptionMapper::isNotFoundOrGone(new S3Exception('NoSuchKey', $command, ['code' => 'NoSuchKey'])));
        $this->assertTrue(S3ExceptionMapper::isNotFoundOrGone(new S3Exception('NoSuchUpload', $command, ['code' => 'NoSuchUpload'])));
        $this->assertFalse(S3ExceptionMapper::isNotFoundOrGone(new S3Exception('AccessDenied', $command, ['code' => 'AccessDenied', 'status_code' => 403])));
    }
}
