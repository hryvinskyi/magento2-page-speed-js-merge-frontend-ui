<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\Block;

use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectTokenInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Block\RequireJsDataCollector;
use Magento\Framework\View\LayoutInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The rendered collector reports under a collect token, while its script tag keeps the plain route key the server
 * reads back from the page.
 *
 * @magentoAppArea frontend
 */
class RequireJsDataCollectorTest extends TestCase
{
    /**
     * The data attribute carries the plain route key and the collector's init call carries the token issued for it.
     *
     * @magentoConfigFixture current_store hryvinskyi_pagespeed/js/merge_enabled 1
     * @return void
     */
    public function testTheCollectorReportsUnderATokenAndTheTagKeepsThePlainRouteKey(): void
    {
        $layout = Bootstrap::getObjectManager()->create(LayoutInterface::class);
        self::assertInstanceOf(LayoutInterface::class, $layout);
        $layout->getUpdate()->addHandle('default')->addHandle('pagespeed_collector_render');
        $block = $layout->createBlock(RequireJsDataCollector::class, 'require_js_data_collector');
        self::assertInstanceOf(RequireJsDataCollector::class, $block);
        $block->setTemplate('Hryvinskyi_PageSpeedJsMergeFrontendUi::require_js_data_collector.phtml');
        $routeKey = $block->getRouteKey();

        $html = $block->toHtml();

        self::assertStringNotContainsString(RequireJsManager::TAG_VALUE_PLACEHOLDER, $html);

        $attributePattern = '/' . preg_quote(RequireJsManager::SCRIPT_TAG_DATA_KEY, '/') . '="([^"]*)"/';
        self::assertSame(1, preg_match($attributePattern, $html, $attribute), $html);
        self::assertSame($routeKey, html_entity_decode($attribute[1], ENT_QUOTES));

        self::assertSame(1, preg_match('/dataCollector\.init\((".*?")\s*,/', $html, $init), $html);
        $collectKey = json_decode($init[1], true);
        self::assertIsString($collectKey);
        self::assertNotSame($routeKey, $collectKey);
        $collectToken = Bootstrap::getObjectManager()->get(CollectTokenInterface::class);
        self::assertInstanceOf(CollectTokenInterface::class, $collectToken);
        self::assertSame($routeKey, $collectToken->routeKeyOf($collectKey));
    }

    /**
     * Nothing is rendered while JS merging is disabled.
     *
     * @magentoConfigFixture current_store hryvinskyi_pagespeed/js/merge_enabled 0
     * @return void
     */
    public function testNothingIsRenderedWhileMergingIsDisabled(): void
    {
        $layout = Bootstrap::getObjectManager()->create(LayoutInterface::class);
        self::assertInstanceOf(LayoutInterface::class, $layout);
        $block = $layout->createBlock(RequireJsDataCollector::class, 'require_js_data_collector');
        self::assertInstanceOf(RequireJsDataCollector::class, $block);
        $block->setTemplate('Hryvinskyi_PageSpeedJsMergeFrontendUi::require_js_data_collector.phtml');

        self::assertSame('', $block->toHtml());
    }
}
