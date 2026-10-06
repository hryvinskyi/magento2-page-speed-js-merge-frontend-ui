<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\Model;

use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\MergeJs;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\BundleModules;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\DesignFixture;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Rendering a page makes sure the bundle file of its route key exists, and never rewrites one that does.
 *
 * @magentoAppArea frontend
 */
class MergeJsTest extends TestCase
{
    /** @var string|null Route key the rendered page carries */
    private $routeKey;

    /** @var DesignFixture|null Fixture module inside the current design */
    private $design;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->design = new DesignFixture(Bootstrap::getObjectManager());
        $this->routeKey = $this->design->routeKey('pagespeed_render');
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        if ($this->routeKey !== null) {
            $bundlePath = $this->bundlePath();
            if (is_file($bundlePath)) {
                unlink($bundlePath);
            }
            $this->resolve(JsList::class)->getCache()->remove(RequireJsManager::CACHE_KEY_PREFIX . $this->routeKey);
        }
        $this->design?->remove();
    }

    /**
     * A page whose bundle file is missing gets it written, inlining each listed script under its module key, and
     * references it.
     *
     * @return void
     */
    public function testRenderingWritesAMissingBundleFile(): void
    {
        $this->storeList();
        $bundlePath = $this->bundlePath();
        self::assertFileDoesNotExist($bundlePath);

        $html = $this->render();

        self::assertFileExists($bundlePath);
        $scripts = BundleModules::scripts((string)file_get_contents($bundlePath));
        self::assertStringContainsString('"a"', $scripts[$this->design()->moduleKey('a.js')] ?? '');
        self::assertStringContainsString('"b"', $scripts[$this->design()->moduleKey('b.js')] ?? '');
        self::assertStringContainsString(basename($bundlePath), $html);
    }

    /**
     * An existing bundle file keeps its inode, modification time and content when a page is rendered.
     *
     * @return void
     */
    public function testRenderingNeverRewritesAnExistingBundleFile(): void
    {
        $this->storeList();
        $bundlePath = $this->bundlePath();
        if (!is_dir(dirname($bundlePath))) {
            mkdir(dirname($bundlePath), 0777, true);
        }
        file_put_contents($bundlePath, '/* existing */');
        touch($bundlePath, time() - 3600);
        clearstatcache();
        $before = stat($bundlePath);

        $this->render();

        clearstatcache();
        $after = stat($bundlePath);
        self::assertSame('/* existing */', file_get_contents($bundlePath));
        self::assertIsArray($before);
        self::assertIsArray($after);
        self::assertSame([$before['ino'], $before['mtime']], [$after['ino'], $after['mtime']]);
    }

    /**
     * A page whose route key has no stored list is sent with no-cache headers until a list exists.
     *
     * @return void
     */
    public function testAPageWithoutAStoredListIsNotCacheable(): void
    {
        $response = $this->resolve(ResponseInterface::class);
        self::assertInstanceOf(HttpResponse::class, $response);
        $response->clearHeader('cache-control');

        $this->render();

        $cacheControl = $response->getHeader('cache-control');
        self::assertInstanceOf(HeaderInterface::class, $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl->getFieldValue());
    }

    /**
     * Render a minimal page carrying this test's route key through the merge step.
     *
     * @return string The rendered HTML
     */
    private function render(): string
    {
        $html = '<html><head><script type="text/javascript" src="' . $this->design()->base()
            . 'requirejs/require.js"></script></head><body><script ' . RequireJsManager::SCRIPT_TAG_DATA_KEY . '="'
            . $this->routeKey() . '" data-pagespeed-ignore-merge>window.x = 1;</script></body></html>';
        $this->resolve(MergeJs::class)->merge($html);

        return $html;
    }

    /**
     * Store a URL list of two fixture scripts under this test's route key.
     *
     * @return void
     */
    private function storeList(): void
    {
        $urls = [];
        foreach (['a', 'b'] as $name) {
            $urls[] = $this->design()->moduleUrl($name . '.js', 'define([], function () { return "' . $name . '"; });');
        }
        self::assertTrue($this->resolve(RequireJsManager::class)->saveUrlList($urls, $this->routeKey()));
    }

    /**
     * @return string Path of the bundle file for the stored list
     */
    private function bundlePath(): string
    {
        return $this->resolve(MergeJs::class)->getRequireJsResultFilePath($this->routeKey());
    }

    /**
     * @return string
     */
    private function routeKey(): string
    {
        self::assertIsString($this->routeKey);

        return $this->routeKey;
    }

    /**
     * @return DesignFixture
     */
    private function design(): DesignFixture
    {
        self::assertInstanceOf(DesignFixture::class, $this->design);

        return $this->design;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private function resolve(string $type): object
    {
        $instance = Bootstrap::getObjectManager()->get($type);
        self::assertInstanceOf($type, $instance);

        return $instance;
    }
}
