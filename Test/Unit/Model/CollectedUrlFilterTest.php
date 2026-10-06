<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Unit\Model;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\CollectedUrlFilter;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Phrase;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Only the route key's own design may report, and only its existing scripts and templates enter a list, one URL per
 * module key.
 */
class CollectedUrlFilterTest extends TestCase
{
    private const STATIC_URL = 'http://shop.test/static/version1700000000/';
    private const SECURE_STATIC_URL = 'https://shop.test/static/version1700000000/';
    private const BASE = 'https://shop.test/static/version1700000000/frontend/Vendor/theme/en_US/';
    private const OLD_BASE = 'https://shop.test/static/version1600000000/frontend/Vendor/theme/en_US/';
    private const DESIGN_BASE_URLS = [
        'http://shop.test/static/version1700000000/frontend/Vendor/theme/en_US/',
        'https://shop.test/static/version1700000000/frontend/Vendor/theme/en_US/',
    ];
    private const FILE_SIZES = [
        'frontend/Vendor/theme/en_US/Vendor_Module/js/a.js' => 100,
        'frontend/Vendor/theme/en_US/Vendor_Module/js/b.min.js' => 20,
        'frontend/Vendor/theme/en_US/Vendor_Module/template/c.html' => 3,
        'frontend/Vendor/theme/en_US/Vendor_Module/css/d.css' => 4,
        'frontend/Vendor/other/en_US/Vendor_Module/js/a.js' => 5,
        'frontend/Vendor/theme/de_DE/Vendor_Module/js/a.js' => 6,
        'adminhtml/Magento/backend/en_US/Vendor_Module/js/a.js' => 7,
    ];

    /**
     * The route key's design base is accepted, secure or not, under any static version.
     *
     * @dataProvider acceptedBaseProvider
     * @param string $base RequireJS base URL
     * @return void
     */
    public function testTheDesignBaseIsAccepted(string $base): void
    {
        self::assertTrue($this->filter()->acceptsBase(self::DESIGN_BASE_URLS, $base));
    }

    /**
     * Bases of the route key's design.
     *
     * @return array<string, array{string}>
     */
    public static function acceptedBaseProvider(): array
    {
        return [
            'secure' => [self::BASE],
            'insecure' => ['http://shop.test/static/version1700000000/frontend/Vendor/theme/en_US/'],
            'another static version' => [self::OLD_BASE],
            'without a static version' => ['https://shop.test/static/frontend/Vendor/theme/en_US/'],
        ];
    }

    /**
     * Any other base is refused, however close to the static content it lies.
     *
     * @dataProvider refusedBaseProvider
     * @param string $base RequireJS base URL
     * @return void
     */
    public function testAnyOtherBaseIsRefused(string $base): void
    {
        self::assertFalse($this->filter()->acceptsBase(self::DESIGN_BASE_URLS, $base));
    }

    /**
     * Bases other than the route key's design.
     *
     * @return array<string, array{string}>
     */
    public static function refusedBaseProvider(): array
    {
        $static = 'https://shop.test/static/version1700000000/';

        return [
            'another theme' => [$static . 'frontend/Vendor/other/en_US/'],
            'another locale' => [$static . 'frontend/Vendor/theme/de_DE/'],
            'admin area' => [$static . 'adminhtml/Magento/backend/en_US/'],
            'static root' => [$static],
            'a directory inside the design' => [$static . 'frontend/Vendor/theme/en_US/Vendor_Module/'],
            'web root' => ['https://shop.test/'],
            'media' => ['https://shop.test/media/'],
            'another host' => ['https://evil.test/static/version1700000000/frontend/Vendor/theme/en_US/'],
            'parent segment' => [$static . 'frontend/Vendor/theme/en_US/../en_US/'],
            'query' => [$static . 'frontend/Vendor/theme/en_US/?x=/'],
            'no trailing slash' => [$static . 'frontend/Vendor/theme/en_US'],
            'empty' => [''],
        ];
    }

    /**
     * No base is accepted when the route key names no design.
     *
     * @return void
     */
    public function testNoBaseIsAcceptedWithoutADesign(): void
    {
        self::assertFalse($this->filter()->acceptsBase([], self::BASE));
    }

    /**
     * Reported URLs are kept only as existing scripts or templates under the base, keyed by module key, first wins.
     *
     * @return void
     */
    public function testReportedUrlsAreKeptOnlyAsExistingScriptsOrTemplatesUnderTheBase(): void
    {
        $kept = $this->filter()->filterReported(self::BASE, [
            self::BASE . 'Vendor_Module/js/a.js',
            self::BASE . 'Vendor_Module/js/b.min.js',
            self::BASE . 'Vendor_Module/template/c.html',
            self::BASE . 'Vendor_Module/js/a.js',
            self::BASE . 'Vendor_Module/css/d.css',
            self::BASE . 'Vendor_Module/js/missing.js',
            self::BASE . 'Vendor_Module/js/a.js?v=1',
            self::BASE . 'Vendor_Module/js/a.js#top',
            self::BASE . 'Vendor_Module/../Vendor_Module/js/a.js',
            self::BASE . 'Vendor_Module/./js/a.js',
            self::BASE . 'Vendor_Module//js/a.js',
            self::OLD_BASE . 'Vendor_Module/js/a.js',
            'https://shop.test/static/version1700000000/frontend/Vendor/other/en_US/Vendor_Module/js/a.js',
            'https://shop.test/static/version1700000000/frontend/Vendor/theme/de_DE/Vendor_Module/js/a.js',
            'https://shop.test/static/version1700000000/adminhtml/Magento/backend/en_US/Vendor_Module/js/a.js',
            'https://shop.test/media/Vendor_Module/js/a.js',
        ]);

        self::assertSame(
            [
                'Vendor_Module/js/a.js' => self::BASE . 'Vendor_Module/js/a.js',
                'Vendor_Module/js/b.min.js' => self::BASE . 'Vendor_Module/js/b.min.js',
                'Vendor_Module/template/c.html' => self::BASE . 'Vendor_Module/template/c.html',
            ],
            $kept
        );
    }

    /**
     * A URL of another theme, locale or area can never stand in for a module of the route key's design.
     *
     * @return void
     */
    public function testAnotherDesignsFileNeverStandsInForAModule(): void
    {
        $static = 'https://shop.test/static/version1700000000/';

        $kept = $this->filter()->filterReported(self::BASE, [
            $static . 'frontend/Vendor/other/en_US/Vendor_Module/js/a.js',
            $static . 'frontend/Vendor/theme/de_DE/Vendor_Module/js/a.js',
            $static . 'adminhtml/Magento/backend/en_US/Vendor_Module/js/a.js',
        ]);

        self::assertSame([], $kept);
    }

    /**
     * Stored URLs of the base's design are kept under any static version or scheme, the first one per module key;
     * URLs of another design, without a file, or whose leading segments differ from the base's are dropped.
     *
     * @return void
     */
    public function testStoredUrlsAreKeptOnlyForTheBasesDesign(): void
    {
        $kept = $this->filter()->filterStored(self::BASE, [
            self::OLD_BASE . 'Vendor_Module/js/a.js',
            self::BASE . 'Vendor_Module/js/a.js',
            'http://shop.test/static/version1700000000/frontend/Vendor/theme/en_US/Vendor_Module/js/b.min.js',
            'https://shop.test/static/version1700000000/frontend/Vendor/other/en_US/Vendor_Module/js/a.js',
            'https://shop.test/static/frontend/Vendor/theme/en_US/Vendor_Module/js/a.js',
            self::OLD_BASE . 'Vendor_Module/js/removed.js',
            self::OLD_BASE,
            'https://shop.test/media/Vendor_Module/js/a.js',
            'https://shop.test/../../app/etc/a.js',
        ]);

        self::assertSame(
            [
                'Vendor_Module/js/a.js' => self::OLD_BASE . 'Vendor_Module/js/a.js',
                'Vendor_Module/js/b.min.js' =>
                    'http://shop.test/static/version1700000000/frontend/Vendor/theme/en_US/Vendor_Module/js/b.min.js',
            ],
            $kept
        );
    }

    /**
     * The total size is the sum of the sizes of the design's files the module keys name; unreadable files count 0.
     *
     * @return void
     */
    public function testTotalBytesAddsUpTheDesignsFiles(): void
    {
        self::assertSame(
            123,
            $this->filter()->totalBytes(self::BASE, [
                'Vendor_Module/js/a.js',
                'Vendor_Module/js/b.min.js',
                'Vendor_Module/template/c.html',
                'Vendor_Module/js/missing.js',
            ])
        );
    }

    /**
     * The filter on a store whose static URL is versioned, with the fixture files under its static root.
     *
     * @return CollectedUrlFilter
     */
    private function filter(): CollectedUrlFilter
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturnCallback(
            static function (string $type, ?bool $secure = null): string {
                self::assertSame(UrlInterface::URL_TYPE_STATIC, $type);

                return $secure === true ? self::SECURE_STATIC_URL : self::STATIC_URL;
            }
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $staticRoot = $this->createMock(ReadInterface::class);
        $staticRoot->method('isFile')->willReturnCallback(
            static fn (string $path): bool => isset(self::FILE_SIZES[$path])
        );
        $staticRoot->method('stat')->willReturnCallback(
            static function (string $path): array {
                if (!isset(self::FILE_SIZES[$path])) {
                    throw new FileSystemException(new Phrase('missing'));
                }

                return ['size' => self::FILE_SIZES[$path]];
            }
        );
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->with(DirectoryList::STATIC_VIEW)->willReturn($staticRoot);

        return new CollectedUrlFilter($storeManager, $filesystem);
    }
}
