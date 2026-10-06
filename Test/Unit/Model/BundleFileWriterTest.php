<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Unit\Model;

use Hryvinskyi\PageSpeedJsMerge\Api\ConfigInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\BundleFileWriter;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Phrase;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A bundle file is written whole when absent and never touched when present; a failure is logged, not thrown.
 */
class BundleFileWriterTest extends TestCase
{
    private string $directory;

    /** @var ConfigInterface&MockObject */
    private ConfigInterface $config;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/bundle-file-writer-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getFilePermission')->willReturn(0644);
        $this->config->method('getFolderPermission')->willReturn(0755);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    /**
     * An absent file is written with the content, its directory created, and no temporary file left behind.
     *
     * @return void
     */
    public function testAnAbsentFileIsWrittenWhole(): void
    {
        $path = $this->directory . '/requirejs/bundle.js';
        $this->logger->expects(self::never())->method('error');

        $this->writer(new File())->writeIfAbsent($path, static fn (): string => 'require.config({});');

        self::assertSame('require.config({});', file_get_contents($path));
        self::assertSame(['bundle.js'], array_values(array_diff(scandir(dirname($path)) ?: [], ['.', '..'])));
    }

    /**
     * An existing file keeps its inode, its modification time and its content, and no content is produced for it.
     *
     * @return void
     */
    public function testAnExistingFileIsNeverTouched(): void
    {
        $path = $this->directory . '/bundle.js';
        file_put_contents($path, 'original');
        touch($path, time() - 3600);
        clearstatcache();
        $before = stat($path);
        $produced = false;

        $this->writer(new File())->writeIfAbsent(
            $path,
            static function () use (&$produced): string {
                $produced = true;

                return 'replacement';
            }
        );

        clearstatcache();
        $after = stat($path);
        self::assertFalse($produced, 'Content was produced for a file that already exists.');
        self::assertSame('original', file_get_contents($path));
        self::assertIsArray($before);
        self::assertIsArray($after);
        self::assertSame($before['ino'], $after['ino']);
        self::assertSame($before['mtime'], $after['mtime']);
    }

    /**
     * A write that fails is logged, throws nothing, and leaves no temporary file behind.
     *
     * @return void
     */
    public function testAFailedWriteIsLoggedAndCleanedUp(): void
    {
        $path = $this->directory . '/bundle.js';
        $deleted = [];
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getParentDirectory')->willReturn($this->directory);
        $driver->method('isDirectory')->willReturn(true);
        $driver->method('isExists')->willReturnCallback(static fn (string $candidate): bool => $candidate !== $path);
        $driver->method('filePutContents')->willThrowException(new FileSystemException(new Phrase('disk full')));
        $driver->method('deleteFile')->willReturnCallback(
            static function (string $candidate) use (&$deleted): bool {
                $deleted[] = $candidate;

                return true;
            }
        );
        $driver->expects(self::never())->method('rename');
        $this->logger->expects(self::once())->method('error');

        $this->writer($driver)->writeIfAbsent($path, static fn (): string => 'content');

        self::assertCount(1, $deleted);
        self::assertStringStartsWith($path . '.', $deleted[0]);
    }

    /**
     * A file whose permissions cannot be changed is still published; the failure is logged at debug level only.
     *
     * @return void
     */
    public function testAFailingPermissionChangeDoesNotStopTheWrite(): void
    {
        $path = $this->directory . '/bundle.js';
        $renamed = [];
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getParentDirectory')->willReturn($this->directory);
        $driver->method('isDirectory')->willReturn(true);
        $driver->method('isExists')->willReturn(false);
        $driver->method('filePutContents')->willReturn(7);
        $driver->method('changePermissions')->willThrowException(new FileSystemException(new Phrase('not owner')));
        $driver->method('rename')->willReturnCallback(
            static function (string $from, string $to) use (&$renamed): bool {
                $renamed[] = $to;

                return true;
            }
        );
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::atLeastOnce())->method('debug');

        $this->writer($driver)->writeIfAbsent($path, static fn (): string => 'content');

        self::assertSame([$path], $renamed);
    }

    /**
     * A rename that moved the file into place but failed to adjust its permissions counts as published.
     *
     * @return void
     */
    public function testARenameThatOnlyFailedToAdjustPermissionsCountsAsPublished(): void
    {
        $path = $this->directory . '/bundle.js';
        $state = new \ArrayObject(['moved' => false]);
        $driver = $this->createMock(DriverInterface::class);
        $driver->method('getParentDirectory')->willReturn($this->directory);
        $driver->method('isDirectory')->willReturn(true);
        $driver->method('isExists')->willReturnCallback(
            static fn (string $candidate): bool => $state['moved'] === true && $candidate === $path
        );
        $driver->method('filePutContents')->willReturn(7);
        $driver->method('rename')->willReturnCallback(
            static function () use ($state): bool {
                $state['moved'] = true;

                throw new FileSystemException(new Phrase('chmod after rename failed'));
            }
        );
        $driver->expects(self::never())->method('deleteFile');
        $this->logger->expects(self::never())->method('error');
        $this->logger->expects(self::once())->method('debug');

        $this->writer($driver)->writeIfAbsent($path, static fn (): string => 'content');
    }

    /**
     * The writer under test on the given driver.
     *
     * @param DriverInterface $driver Filesystem driver
     * @return BundleFileWriter
     */
    private function writer(DriverInterface $driver): BundleFileWriter
    {
        return new BundleFileWriter($driver, $this->config, $this->logger);
    }

    /**
     * Remove a directory and everything in it.
     *
     * @param string $directory Directory to remove
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }
            unlink($path);
        }
        rmdir($directory);
    }
}
