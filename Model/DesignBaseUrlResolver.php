<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\DesignBaseUrlResolverInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reads the theme hash and the store id from the end of the route key, refuses a store other than the current one,
 * finds the frontend theme whose code hashes to the route key's hash, and asks the asset repository for that theme's
 * static base URL in the store's locale.
 */
class DesignBaseUrlResolver implements DesignBaseUrlResolverInterface
{
    private const ROUTE_KEY_DESIGN = '/___([0-9a-f]{32})___(\d+)$/';
    private const LOCALE_PATH = 'general/locale/code';
    private const THEME_TYPES = [ThemeInterface::TYPE_PHYSICAL, ThemeInterface::TYPE_VIRTUAL];

    /**
     * @param StoreManagerInterface $storeManager Resolves the current store
     * @param ThemeProviderInterface $themeProvider Lists the frontend themes
     * @param ScopeConfigInterface $scopeConfig Reads the store's locale
     * @param AssetRepository $assetRepository Builds a theme's static base URL
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly ThemeProviderInterface $themeProvider,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AssetRepository $assetRepository
    ) {
    }

    /**
     * @inheritDoc
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     * @throws \Magento\Framework\Exception\LocalizedException When the asset repository cannot build the URL
     */
    public function forRouteKey(string $routeKey): array
    {
        if (preg_match(self::ROUTE_KEY_DESIGN, $routeKey, $match) !== 1) {
            return [];
        }

        $storeId = (string)$this->storeManager->getStore()->getId();
        if ($match[2] !== $storeId) {
            return [];
        }

        $locale = $this->scopeConfig->getValue(self::LOCALE_PATH, ScopeInterface::SCOPE_STORE, $storeId);
        $theme = $this->themeByCodeHash($match[1]);
        if (!is_string($locale) || $locale === '' || $theme === null) {
            return [];
        }

        $baseUrls = [];
        foreach ([false, true] as $secure) {
            $baseUrls[] = rtrim($this->assetRepository->getUrlWithParams('/', [
                'area' => Area::AREA_FRONTEND,
                'themeModel' => $theme,
                'locale' => $locale,
                '_secure' => $secure,
            ]), '/') . '/';
        }

        return array_values(array_unique($baseUrls));
    }

    /**
     * The frontend theme whose code hashes to the given value.
     *
     * @param string $codeHash md5 of the theme code
     * @return ThemeInterface|null
     */
    private function themeByCodeHash(string $codeHash): ?ThemeInterface
    {
        foreach (self::THEME_TYPES as $type) {
            foreach ($this->themeProvider->getThemeCustomizations(Area::AREA_FRONTEND, $type) as $theme) {
                if ($theme instanceof ThemeInterface && md5((string)$theme->getCode()) === $codeHash) {
                    return $theme;
                }
            }
        }

        return null;
    }
}
