<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Aws\S3\Exception\S3Exception;
use MadeByClowd\Documentable\Exceptions\MultipartPartTooSmallException;
use MadeByClowd\Documentable\Exceptions\MultipartUploadNotFoundException;
use Throwable;

/**
 * Maps AWS S3 SDK exceptions and error codes to domain-specific exceptions.
 *
 * @internal
 */
final class S3ExceptionMapper
{
    /**
     * Maps an S3Exception during multipart operations to typed exceptions.
     */
    public static function mapMultipart(S3Exception $e, string $path, string $uploadId): Throwable
    {
        $code = $e->getAwsErrorCode();

        if ($code === 'NoSuchUpload') {
            return MultipartUploadNotFoundException::forUpload($path, $uploadId, $e);
        }

        if ($code === 'EntityTooSmall') {
            return MultipartPartTooSmallException::forUpload($path, $uploadId, $e);
        }

        return $e;
    }

    /**
     * Determines whether an exception represents a 404, NoSuchKey, or NoSuchUpload response.
     */
    public static function isNotFoundOrGone(Throwable $e): bool
    {
        if (! $e instanceof S3Exception) {
            return false;
        }

        $code = $e->getAwsErrorCode();
        $statusCode = $e->getStatusCode();

        return $statusCode === 404
            || $code === 'NoSuchKey'
            || $code === 'NotFound'
            || $code === 'NoSuchUpload';
    }

    /**
     * Determines whether an exception indicates the caller lacks permissions or the feature is unsupported.
     */
    public static function isAccessDeniedOrNotImplemented(Throwable $e): bool
    {
        if (! $e instanceof S3Exception) {
            return false;
        }

        $code = $e->getAwsErrorCode();
        $statusCode = $e->getStatusCode();

        return $statusCode === 403
            || $code === 'AccessDenied'
            || $code === 'NotImplemented'
            || $code === 'MethodNotAllowed'
            || $statusCode === 405
            || $statusCode === 501;
    }
}
