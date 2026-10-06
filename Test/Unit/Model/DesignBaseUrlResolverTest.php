<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Unit\Model;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\DesignBaseUrlResolver;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Framework\View\Design\ThemeInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A route key names its design by the hash of its theme code and its store id; the base URL is that theme's static
 * URL in the store's locale, and only for the current store.
 */
class DesignBaseUrlResolverTest extends TestCase
{
    /**
     * The route key's theme, found by the hash of its code, gives the insecure and secure base URLs.
     *
     * @return void
     */
    public function testTheRouteKeysThemeGivesTheBaseUrls(): void
    {
        $routeKey = 'catalog_product_view____catalog_product_view_type_simple___' . md5('Vendor/theme') . '___1';

        self::assertSame(
            [
                'http://shop.test/static/version1/frontend/Vendor/theme/da_DK/',
                'https://shop.test/static/version1/frontend/Vendor/theme/da_DK/',
            ],
            $this->resolver()->forRouteKey($routeKey)
        );
    }

    /**
     * A theme that exists only as a customization is found too.
     *
     * @return void
     */
    public function testAVirtualThemeIsFound(): void
    {
        $routeKey = 'cms_index_index___' . md5('Vendor/custom') . '___1';

        self::assertSame(
            [
                'http://shop.test/static/version1/frontend/Vendor/custom/da_DK/',
                'https://shop.test/static/version1/frontend/Vendor/custom/da_DK/',
            ],
            $this->resolver()->forRouteKey($routeKey)
        );
    }

    /**
     * Route keys of another store, of an unknown theme, or without a design part name no base URL.
     *
     * @dataProvider unresolvableRouteKeyProvider
     * @param string $routeKey Route key
     * @return void
     */
    public function testAnUnresolvableRouteKeyNamesNoBaseUrl(string $routeKey): void
    {
        self::assertSame([], $this->resolver()->forRouteKey($routeKey));
    }

    /**
     * Route keys the current store cannot resolve to a design.
     *
     * @return array<string, array{string}>
     */
    public static function unresolvableRouteKeyProvider(): array
    {
        return [
            'another store' => ['catalog_product_view___' . md5('Vendor/theme') . '___2'],
            'unknown theme' => ['catalog_product_view___' . md5('Vendor/missing') . '___1'],
            'no design part' => ['catalog_product_view'],
            'store id not at the end' => ['catalog_product_view___' . md5('Vendor/theme') . '___1___x'],
        ];
    }

    /**
     * The resolver on store 1 with locale da_DK, one physical and one virtual frontend theme.
     *
     * @return DesignBaseUrlResolver
     */
    private function resolver(): DesignBaseUrlResolver
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn('1');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $physical = $this->createMock(ThemeInterface::class);
        $physical->method('getCode')->willReturn('Vendor/theme');
        $virtual = $this->createMock(ThemeInterface::class);
        $virtual->method('getCode')->willReturn('Vendor/custom');
        $themeProvider = $this->createMock(ThemeProviderInterface::class);
        $themeProvider->method('getThemeCustomizations')->willReturnCallback(
            static fn (string $area, int $type): array => $area !== 'frontend' ? [] : match ($type) {
                ThemeInterface::TYPE_PHYSICAL => [$physical],
                ThemeInterface::TYPE_VIRTUAL => [$virtual],
                default => [],
            }
        );

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path, string $scope, mixed $scopeCode): ?string
                => $path === 'general/locale/code' && $scope === 'store' && $scopeCode === '1' ? 'da_DK' : null
        );

        $assetRepository = $this->createMock(AssetRepository::class);
        $assetRepository->method('getUrlWithParams')->willReturnCallback(
            static function (string $fileId, array $params): string {
                $theme = $params['themeModel'] ?? null;
                self::assertInstanceOf(ThemeInterface::class, $theme);
                self::assertSame('frontend', $params['area'] ?? null);

                return (($params['_secure'] ?? false) === true ? 'https' : 'http')
                    . '://shop.test/static/version1/frontend/' . $theme->getCode() . '/'
                    . (is_string($params['locale'] ?? null) ? $params['locale'] : '') . $fileId;
            }
        );

        return new DesignBaseUrlResolver($storeManager, $themeProvider, $scopeConfig, $assetRepository);
    }
}
