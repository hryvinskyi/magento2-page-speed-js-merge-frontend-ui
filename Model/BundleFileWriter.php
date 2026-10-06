<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeedJsMerge\Api\ConfigInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\BundleFileWriterInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\DriverInterface;
use Psr\Log\LoggerInterface;

/**
 * A bundle's name is derived from its URL list and the modification times of the listed files, so two writers that
 * race for the same absent name build the same content; the second rename swaps in a complete file and a reader
 * never sees a partial one.
 *
 * Permissions are applied on a best-effort basis: a file that cannot be given the configured mode is still
 * published, as a bundle that is readable by the web server is all that matters.
 */
class BundleFileWriter implements BundleFileWriterInterface
{
    private const TEMPORARY_SUFFIX = '.tmp';

    /**
     * @param DriverInterface $driver Local filesystem driver
     * @param ConfigInterface $config Permissions for created files and directories
     * @param LoggerInterface $logger Receives write failures
     */
    public function __construct(
        private readonly DriverInterface $driver,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function writeIfAbsent(string $path, callable $content): void
    {
        $temporaryPath = $path . '.' . bin2hex(random_bytes(8)) . self::TEMPORARY_SUFFIX;
        try {
            if ($this->driver->isExists($path)) {
                return;
            }

            $directory = $this->driver->getParentDirectory($path);
            if (!$this->driver->isDirectory($directory)) {
                $this->driver->createDirectory($directory, $this->config->getFolderPermission());
            }

            $this->driver->filePutContents($temporaryPath, $content());
        } catch (FileSystemException $exception) {
            $this->fail($path, $temporaryPath, $exception);

            return;
        }

        $this->applyFilePermission($temporaryPath);
        try {
            $this->driver->rename($temporaryPath, $path);
        } catch (FileSystemException $exception) {
            if ($this->exists($temporaryPath) || !$this->exists($path)) {
                $this->fail($path, $temporaryPath, $exception);

                return;
            }
            // The file was moved into place; only adjusting its permissions afterwards failed.
            $this->logger->debug(
                'The merged RequireJS bundle was published, but its permissions could not be adjusted.',
                ['path' => $path, 'exception' => $exception]
            );

            return;
        }

        $this->applyFilePermission($path);
    }

    /**
     * Give a file the configured mode, logging rather than failing when that is not possible.
     *
     * @param string $path File path
     * @return void
     */
    private function applyFilePermission(string $path): void
    {
        try {
            $this->driver->changePermissions($path, $this->config->getFilePermission());
        } catch (FileSystemException $exception) {
            $this->logger->debug(
                'The permissions of a merged RequireJS bundle file could not be changed.',
                ['path' => $path, 'exception' => $exception]
            );
        }
    }

    /**
     * Log a failed write and remove what it left behind.
     *
     * @param string $path Bundle path
     * @param string $temporaryPath Temporary file of the failed write
     * @param FileSystemException $exception The failure
     * @return void
     */
    private function fail(string $path, string $temporaryPath, FileSystemException $exception): void
    {
        $this->logger->error(
            'The merged RequireJS bundle could not be written.',
            ['path' => $path, 'exception' => $exception]
        );

        try {
            if ($this->driver->isExists($temporaryPath)) {
                $this->driver->deleteFile($temporaryPath);
            }
        } catch (FileSystemException $cleanupException) {
            $this->logger->error(
                'A temporary RequireJS bundle file could not be removed.',
                ['path' => $temporaryPath, 'exception' => $cleanupException]
            );
        }
    }

    /**
     * Whether a path exists, treating an unreadable path as absent.
     *
     * @param string $path Path
     * @return bool
     */
    private function exists(string $path): bool
    {
        try {
            return $this->driver->isExists($path);
        } catch (FileSystemException) {
            return false;
        }
    }
}
