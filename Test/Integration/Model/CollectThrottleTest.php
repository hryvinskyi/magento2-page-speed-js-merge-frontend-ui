<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\Model;

use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectThrottleInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\CollectThrottle;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The hold lives in the URL list storage, is per route key, and ends with its lifetime.
 */
class CollectThrottleTest extends TestCase
{
    /** @var \ArrayObject<int, string>|null Route keys this test claimed, released afterwards */
    private $claimedKeys;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->claimedKeys = new \ArrayObject();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $jsList = Bootstrap::getObjectManager()->get(JsList::class);
        self::assertInstanceOf(JsList::class, $jsList);
        $cache = $jsList->getCache();
        foreach ($this->claimedKeys?->getArrayCopy() ?? [] as $routeKey) {
            // The throttle's hold entry for the route key.
            $cache->remove('collect_hold_' . md5($routeKey));
        }
    }

    /**
     * The first claim for a route key succeeds, the next is refused, and another route key is not affected.
     *
     * @return void
     */
    public function testAHoldIsPerRouteKey(): void
    {
        $throttle = Bootstrap::getObjectManager()->get(CollectThrottleInterface::class);
        self::assertInstanceOf(CollectThrottleInterface::class, $throttle);
        $routeKey = $this->routeKey();
        $otherRouteKey = $this->routeKey();

        self::assertTrue($throttle->claim($routeKey));
        self::assertFalse($throttle->claim($routeKey));
        self::assertTrue($throttle->claim($otherRouteKey));
    }

    /**
     * Once the hold's lifetime has passed, the route key can be claimed again.
     *
     * @return void
     */
    public function testAHoldEndsWithItsLifetime(): void
    {
        $throttle = Bootstrap::getObjectManager()->create(CollectThrottle::class, ['holdSeconds' => 1]);
        self::assertInstanceOf(CollectThrottle::class, $throttle);
        $routeKey = $this->routeKey();

        self::assertTrue($throttle->claim($routeKey));
        self::assertFalse($throttle->claim($routeKey));
        sleep(2);
        self::assertTrue($throttle->claim($routeKey));
    }

    /**
     * A fresh route key, remembered for release.
     *
     * @return string
     */
    private function routeKey(): string
    {
        $routeKey = 'pagespeed_throttle.' . bin2hex(random_bytes(6)) . '___1';
        $this->claimedKeys?->append($routeKey);

        return $routeKey;
    }
}
