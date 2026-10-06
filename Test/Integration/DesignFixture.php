<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\DesignInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;

/**
 * Module files of a fixture module inside the current store's real frontend design, and route keys of that design.
 *
 * Files are written under the design's static directory, which no database transaction rolls back; remove() deletes
 * the fixture module again.
 */
class DesignFixture
{
    private readonly string $themeCode;
    private readonly string $storeId;
    private readonly string $base;
    private readonly string $designDirectory;
    private readonly string $moduleName;

    /**
     * @param ObjectManagerInterface $objectManager Object manager of the test application
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $design = $objectManager->get(DesignInterface::class);
        $storeManager = $objectManager->get(StoreManagerInterface::class);
        $scopeConfig = $objectManager->get(ScopeConfigInterface::class);
        $assetRepository = $objectManager->get(AssetRepository::class);
        $filesystem = $objectManager->get(Filesystem::class);
        if (!$design instanceof DesignInterface
            || !$storeManager instanceof StoreManagerInterface
            || !$scopeConfig instanceof ScopeConfigInterface
            || !$assetRepository instanceof AssetRepository
            || !$filesystem instanceof Filesystem
        ) {
            throw new RuntimeException('The test application lacks a service the fixture needs.');
        }

        $this->themeCode = (string)$design->getDesignTheme()->getCode();
        $this->storeId = (string)$storeManager->getStore()->getId();
        $locale = $scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $this->storeId);
        $this->base = rtrim($assetRepository->getUrlWithParams('/', []), '/') . '/';
        $this->designDirectory = rtrim($filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->getAbsolutePath(), '/')
            . '/frontend/' . $this->themeCode . '/' . (is_string($locale) ? $locale : '') . '/';
        $this->moduleName = 'PagespeedFixture_' . bin2hex(random_bytes(6));
    }

    /**
     * A route key of the current design and store, unique to the fixture.
     *
     * @param string $handles The leading layout-handle part of the route key
     * @param string|null $storeId Store id the route key carries; the current store when null
     * @return string
     */
    public function routeKey(string $handles, ?string $storeId = null): string
    {
        return $handles . '_' . strtolower($this->moduleName) . '___' . md5($this->themeCode)
            . '___' . ($storeId ?? $this->storeId);
    }

    /**
     * The RequireJS base URL a page of the current design reports.
     *
     * @return string
     */
    public function base(): string
    {
        return $this->base;
    }

    /**
     * The static URL of the current store, in front of the area directories.
     *
     * @return string
     */
    public function staticRootUrl(): string
    {
        $themePath = 'frontend/' . $this->themeCode . '/';
        $position = strpos($this->base, $themePath);

        return $position === false ? $this->base : substr($this->base, 0, $position);
    }

    /**
     * Create a file of the fixture module and return its URL.
     *
     * @param string $fileName File name under the module
     * @param string $content File content
     * @return string
     */
    public function moduleUrl(string $fileName, string $content): string
    {
        $path = $this->designDirectory . $this->moduleName . '/' . $fileName;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);

        return $this->base . $this->moduleKey($fileName);
    }

    /**
     * The module key the bundle gives a file of the fixture module.
     *
     * @param string $fileName File name under the module
     * @return string
     */
    public function moduleKey(string $fileName): string
    {
        return $this->moduleName . '/' . $fileName;
    }

    /**
     * Delete the fixture module's files.
     *
     * @return void
     */
    public function remove(): void
    {
        $this->removeDirectory($this->designDirectory . $this->moduleName);
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
