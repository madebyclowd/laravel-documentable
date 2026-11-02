<?php

declare(strict_types=1);

namespace MadeByClowd\Documentable\Services;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use MadeByClowd\Documentable\Contracts\MultipartUploadDriver;
use MadeByClowd\Documentable\Exceptions\UnsupportedMultipartDriverException;

/**
 * Picks the multipart driver for a disk from the disk's Flysystem driver name.
 *
 * @internal
 */
final class MultipartDriverResolver
{
    public function __construct(
        private readonly Config $config,
        private readonly Container $container,
    ) {}

    public function forDisk(string $disk): MultipartUploadDriver
    {
        $flysystemDriver = (string) $this->config->get("filesystems.disks.{$disk}.driver");
        $driverClass = $this->config->get("documentable.multipart.drivers.{$flysystemDriver}");

        if (! $driverClass) {
            throw UnsupportedMultipartDriverException::forDisk($disk, $flysystemDriver);
        }

        return $this->container->make($driverClass);
    }
}
