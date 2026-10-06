<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectedUrlFilterInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The module key is computed the way the bundle computes it: the URL's path segments after as many segments as the
 * base URL has. A reported URL must start with the base exactly, so its key is the rest of the URL; a stored URL
 * must match the base in its leading segments apart from the static version segment and the scheme, which a
 * deployment or a secure page changes without changing the key.
 */
class CollectedUrlFilter implements CollectedUrlFilterInterface
{
    private const LEADING_VERSION_SEGMENT = '#^version\d+/#';
    private const TRAILING_VERSION_SEGMENT = '#version\d+/$#';
    private const SCHEME = '#^https?:#';
    private const ACCEPTED_SUFFIXES = ['.js', '.html'];

    /**
     * @param StoreManagerInterface $storeManager Resolves the current store's static content URL
     * @param Filesystem $filesystem Gives read access to the static content root
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Filesystem $filesystem
    ) {
    }

    /**
     * @inheritDoc
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    public function acceptsBase(array $designBaseUrls, string $base): bool
    {
        if (!str_ends_with($base, '/') || $this->hasUnsafeCharacters($base)) {
            return false;
        }

        $directory = $this->staticDirectory($base);
        if ($directory === null) {
            return false;
        }

        foreach ($designBaseUrls as $designBaseUrl) {
            if ($this->staticDirectory($designBaseUrl) === $directory) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    public function filterReported(string $base, array $urls): array
    {
        $directory = $this->staticDirectory($base);
        if ($directory === null) {
            return [];
        }

        $kept = [];
        foreach ($urls as $url) {
            if (!str_starts_with($url, $base)) {
                continue;
            }

            $moduleKey = $this->moduleKey($base, $url);
            if ($moduleKey !== null && !isset($kept[$moduleKey]) && $this->isStaticFile($directory . $moduleKey)) {
                $kept[$moduleKey] = $url;
            }
        }

        return $kept;
    }

    /**
     * @inheritDoc
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    public function filterStored(string $base, array $urls): array
    {
        $directory = $this->staticDirectory($base);
        if ($directory === null) {
            return [];
        }

        $kept = [];
        foreach ($urls as $url) {
            if ($this->staticDirectory($this->leadingSegments($base, $url)) !== $directory) {
                continue;
            }

            $moduleKey = $this->moduleKey($base, $url);
            if ($moduleKey !== null && !isset($kept[$moduleKey]) && $this->isStaticFile($directory . $moduleKey)) {
                $kept[$moduleKey] = $url;
            }
        }

        return $kept;
    }

    /**
     * @inheritDoc
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    public function totalBytes(string $base, array $moduleKeys): int
    {
        $directory = $this->staticDirectory($base);
        if ($directory === null) {
            return 0;
        }

        $staticRoot = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW);
        $total = 0;
        foreach ($moduleKeys as $moduleKey) {
            try {
                $size = $staticRoot->stat($directory . $moduleKey)['size'] ?? 0;
            } catch (FileSystemException|ValidatorException) {
                continue;
            }
            $total += is_int($size) ? $size : 0;
        }

        return $total;
    }

    /**
     * Module key of a URL under the base, when it names a script or template by a clean path.
     *
     * @param string $base Base URL ending in "/"
     * @param string $url Module URL
     * @return string|null
     */
    private function moduleKey(string $base, string $url): ?string
    {
        if ($this->hasUnsafeCharacters($url)) {
            return null;
        }

        $moduleKey = implode('/', array_slice(explode('/', $url), $this->segmentCount($base)));
        if (!$this->hasAcceptedSuffix($moduleKey) || !$this->hasCleanSegments($moduleKey)) {
            return null;
        }

        return $moduleKey;
    }

    /**
     * The URL's leading segments, as many as the base has, followed by "/".
     *
     * @param string $base Base URL ending in "/"
     * @param string $url Any URL
     * @return string
     */
    private function leadingSegments(string $base, string $url): string
    {
        return implode('/', array_slice(explode('/', $url), 0, $this->segmentCount($base))) . '/';
    }

    /**
     * Number of segments before the module key, counted the way the bundle counts them.
     *
     * @param string $base Base URL ending in "/"
     * @return int
     */
    private function segmentCount(string $base): int
    {
        return count(explode('/', $base)) - 1;
    }

    /**
     * Path of a URL relative to the current store's static content URL, without the version segment and scheme.
     *
     * @param string $url Absolute URL
     * @return string|null The path, or null when the URL lies elsewhere or its path has an unclean segment
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    private function staticDirectory(string $url): ?string
    {
        $url = (string)preg_replace(self::SCHEME, '', $url);
        foreach ($this->staticBaseUrls() as $staticBaseUrl) {
            if (!str_starts_with($url, $staticBaseUrl)) {
                continue;
            }

            $path = (string)preg_replace(self::LEADING_VERSION_SEGMENT, '', substr($url, strlen($staticBaseUrl)));

            return $path === '' || $this->hasCleanSegments(rtrim($path, '/')) ? $path : null;
        }

        return null;
    }

    /**
     * The current store's static content URLs, insecure and secure, without the version segment and scheme.
     *
     * @return list<string>
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    private function staticBaseUrls(): array
    {
        $store = $this->storeManager->getStore();
        if (!$store instanceof Store) {
            return [];
        }

        $baseUrls = [];
        foreach ([false, true] as $secure) {
            $baseUrl = (string)preg_replace(
                self::TRAILING_VERSION_SEGMENT,
                '',
                $store->getBaseUrl(UrlInterface::URL_TYPE_STATIC, $secure)
            );
            $baseUrls[] = (string)preg_replace(self::SCHEME, '', $baseUrl);
        }

        return array_values(array_unique($baseUrls));
    }

    /**
     * Whether a URL carries a query, a fragment or a backslash.
     *
     * @param string $url URL
     * @return bool
     */
    private function hasUnsafeCharacters(string $url): bool
    {
        return str_contains($url, '?') || str_contains($url, '#') || str_contains($url, '\\');
    }

    /**
     * Whether a path ends in one of the suffixes a bundle can inline.
     *
     * @param string $path Path
     * @return bool
     */
    private function hasAcceptedSuffix(string $path): bool
    {
        foreach (self::ACCEPTED_SUFFIXES as $suffix) {
            if (str_ends_with($path, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a path has no empty, "." or ".." segment.
     *
     * @param string $path Path
     * @return bool
     */
    private function hasCleanSegments(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a file exists at the path under the static content root.
     *
     * @param string $path Static-relative path with clean segments
     * @return bool
     */
    private function isStaticFile(string $path): bool
    {
        try {
            return $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW)->isFile($path);
        } catch (FileSystemException|ValidatorException) {
            return false;
        }
    }
}
