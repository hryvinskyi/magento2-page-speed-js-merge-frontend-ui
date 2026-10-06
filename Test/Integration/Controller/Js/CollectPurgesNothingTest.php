<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\Controller\Js;

use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMerge\Model\Cache\PageCachePurger;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectTokenInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\MergeJs;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\DesignFixture;
use Laminas\Http\Headers;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager as TestObjectManager;
use Magento\TestFramework\Request as TestRequest;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * The collect endpoint reports RequireJS modules a page loaded; whatever it receives, it must not send a purge
 * to any page cache.
 *
 * @magentoAppArea frontend
 */
class CollectPurgesNothingTest extends AbstractController
{
    /** @var \ArrayObject<int, string>|null Page cache purge requests the endpoint made, one entry per purge call. */
    private $purgeCalls;

    /** @var string|null */
    private $routeKey;

    /** @var string|null The client address the request carried before the test, restored afterwards. */
    private $remoteAddress;

    /** @var DesignFixture|null Fixture module inside the current design */
    private $design;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->remoteAddress = is_string($address) ? $address : null;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $calls = new \ArrayObject();
        $this->purgeCalls = $calls;
        $purger = new class ($calls) extends PageCachePurger {
            /** @var \ArrayObject<int, string> */
            private \ArrayObject $calls;

            /**
             * @param \ArrayObject<int, string> $calls
             */
            public function __construct(\ArrayObject $calls)
            {
                $this->calls = $calls;
            }

            /**
             * @param array<int, string> $tags
             * @return void
             */
            public function purgeByTags(array $tags): void
            {
                $this->calls[] = implode(',', $tags);
            }
        };
        $this->objectManager()->addSharedInstance($purger, PageCachePurger::class);

        $this->design = new DesignFixture($this->objectManager());
        $this->routeKey = $this->design->routeKey('pagespeed_purge');
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $objectManager = $this->objectManager();
        $objectManager->removeSharedInstance(PageCachePurger::class);

        $routeKey = $this->routeKey;
        if ($routeKey !== null) {
            // The bundle is named after the stored list, so its path is taken before the list is removed.
            $bundle = $this->resolve(MergeJs::class)->getRequireJsResultFilePath($routeKey);
            if (is_file($bundle)) {
                unlink($bundle);
            }
            $cache = $this->resolve(JsList::class)->getCache();
            $cache->remove(RequireJsManager::CACHE_KEY_PREFIX . $routeKey);
            // The throttle's hold entry for this route key.
            $cache->remove('collect_hold_' . md5($routeKey));
        }
        $this->design?->remove();
        if ($this->remoteAddress === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->remoteAddress;
        }

        parent::tearDown();
    }

    /**
     * A collect that reports new modules for a route key, with the page's own cache tags attached, sends no purge.
     *
     * @return void
     */
    public function testACollectThatAddsNewModulesSendsNoPurge(): void
    {
        $routeKey = $this->routeKey;
        $design = $this->design;
        self::assertIsString($routeKey);
        self::assertInstanceOf(DesignFixture::class, $design);
        $urls = [
            $design->moduleUrl('a.js', 'define([], function () { return 1; });'),
            $design->moduleUrl('b.js', 'define([], function () { return 2; });'),
        ];

        $request = $this->request();
        $request->setMethod('POST');
        $request->setPostValue([
            'key' => $this->resolve(CollectTokenInterface::class)->issue($routeKey),
            'tags' => 'store,cms_b,cat_c',
            'base' => $design->base(),
            'list' => $urls,
        ]);
        $headers = $request->getHeaders();
        self::assertInstanceOf(Headers::class, $headers);
        $headers->addHeaderLine('X-Requested-With', 'XMLHttpRequest');

        $this->dispatch('pagespeedjsmerge/js/collect');

        $response = $this->getResponse();
        self::assertInstanceOf(HttpResponse::class, $response);
        self::assertSame('{"result":true}', $response->getBody(), 'The collect was not accepted.');
        self::assertSame(
            $urls,
            $this->resolve(RequireJsManager::class)->loadUrlList($routeKey),
            'The collect did not add the reported modules, so the step that used to purge never ran.'
        );
        $calls = $this->purgeCalls === null ? [] : $this->purgeCalls->getArrayCopy();
        self::assertSame(
            [],
            $calls,
            'The collect endpoint sent a page cache purge for tags: ' . implode(' | ', $calls)
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private function resolve(string $type): object
    {
        $instance = $this->objectManager()->get($type);
        self::assertInstanceOf($type, $instance);

        return $instance;
    }

    /**
     * @return TestObjectManager
     */
    private function objectManager(): TestObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(TestObjectManager::class, $objectManager);

        return $objectManager;
    }

    /**
     * @return TestRequest
     */
    private function request(): TestRequest
    {
        $request = $this->getRequest();
        self::assertInstanceOf(TestRequest::class, $request);

        return $request;
    }
}
