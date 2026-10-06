<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Unit\Model;

use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\CollectThrottle;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zend_Cache_Core;

/**
 * The first claim for a route key sets an expiring hold; a claim while the hold is active is refused.
 */
class CollectThrottleTest extends TestCase
{
    /** @var Zend_Cache_Core&MockObject */
    private Zend_Cache_Core $cache;

    /** @var JsList&MockObject */
    private JsList $jsList;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->cache = $this->createMock(Zend_Cache_Core::class);
        $this->jsList = $this->createMock(JsList::class);
        $this->jsList->method('getCache')->willReturn($this->cache);
    }

    /**
     * Without an active hold, the claim sets one for the configured lifetime and succeeds.
     *
     * @return void
     */
    public function testAClaimWithoutAnActiveHoldSetsOne(): void
    {
        $this->cache->method('test')->willReturn(false);
        $this->cache->expects(self::once())
            ->method('save')
            ->with(self::anything(), self::matchesRegularExpression('/^[a-zA-Z0-9_]+$/'), [], 45)
            ->willReturn(true);

        self::assertTrue((new CollectThrottle($this->jsList, 45))->claim('catalog_product_view.with-dots___1'));
    }

    /**
     * While a hold is active, the claim is refused and nothing is written.
     *
     * @return void
     */
    public function testAClaimWhileAHoldIsActiveIsRefused(): void
    {
        $this->cache->method('test')->willReturn(time());
        $this->cache->expects(self::never())->method('save');

        self::assertFalse((new CollectThrottle($this->jsList))->claim('catalog_product_view___1'));
    }

    /**
     * Holds of different route keys are kept apart.
     *
     * @return void
     */
    public function testHoldsOfDifferentRouteKeysAreKeptApart(): void
    {
        $holdIds = [];
        $this->cache->method('test')->willReturn(false);
        $this->cache->method('save')->willReturnCallback(
            static function (mixed $data, string $id) use (&$holdIds): bool {
                $holdIds[] = $id;

                return true;
            }
        );

        $throttle = new CollectThrottle($this->jsList);
        $throttle->claim('checkout_index_index___1');
        $throttle->claim('checkout_index_index___2');

        self::assertCount(2, array_unique($holdIds));
    }

    /**
     * A hold that cannot be written does not let the claim succeed.
     *
     * @return void
     */
    public function testAHoldThatCannotBeWrittenRefusesTheClaim(): void
    {
        $this->cache->method('test')->willReturn(false);
        $this->cache->method('save')->willReturn(false);

        self::assertFalse((new CollectThrottle($this->jsList))->claim('catalog_product_view___1'));
    }
}
