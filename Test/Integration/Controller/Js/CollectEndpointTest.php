<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\Controller\Js;

use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectThrottleInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectTokenInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\CollectThrottle;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\MergeJs;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\RecordCollectedUrls;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\BundleModules;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Integration\DesignFixture;
use Laminas\Http\Headers;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager as TestObjectManager;
use Magento\TestFramework\Request as TestRequest;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * The collect endpoint accepts only reports under a token the server issued, from the route key's own design;
 * stores only that design's scripts and templates, adding modules without replacing any; takes the hold only for a
 * report that adds something; and never deletes or rewrites a bundle file.
 *
 * @magentoAppArea frontend
 */
class CollectEndpointTest extends AbstractController
{
    /** @var DesignFixture|null Fixture module inside the current design */
    private $design;

    /** @var string|null Route key this test reports under */
    private $routeKey;

    /** @var string|null The client address the request carried before the test, restored afterwards */
    private $remoteAddress;

    /** @var \ArrayObject<int, string>|null Bundle files this test may have caused, removed afterwards */
    private $bundlePaths;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();

        $address = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->remoteAddress = is_string($address) ? $address : null;
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        $this->bundlePaths = new \ArrayObject();
        $this->design = new DesignFixture($this->objectManager());
        $this->routeKey = $this->design->routeKey('pagespeed_collect');

        $this->objectManager()->removeSharedInstance(RecordCollectedUrls::class);
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $objectManager = $this->objectManager();
        $objectManager->removeSharedInstance(CollectThrottle::class);
        $objectManager->removeSharedInstance(RecordCollectedUrls::class);

        $routeKey = $this->routeKey;
        if ($routeKey !== null) {
            $this->rememberBundlePath();
            $cache = $this->resolve(JsList::class)->getCache();
            $cache->remove(RequireJsManager::CACHE_KEY_PREFIX . $routeKey);
            // The throttle's hold entry for this route key.
            $cache->remove('collect_hold_' . md5($routeKey));
        }
        foreach ($this->bundlePaths?->getArrayCopy() ?? [] as $bundlePath) {
            if (is_file($bundlePath)) {
                unlink($bundlePath);
            }
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
     * A key the server did not issue is refused and leaves no list, no bundle file and no hold.
     *
     * @dataProvider unissuedKeyProvider
     * @param callable(string, CollectTokenInterface): string $key Builds the key from the route key
     * @return void
     */
    public function testAKeyTheServerDidNotIssueIsRefusedAndWritesNothing(callable $key): void
    {
        $routeKey = $this->routeKey();
        $urls = $this->fixtureUrls(2);
        $bundleDirectory = dirname($this->resolve(MergeJs::class)->getRequireJsResultFilePath($routeKey));
        $bundlesBefore = $this->filesIn($bundleDirectory);

        $result = $this->collect([
            'key' => $key($routeKey, $this->resolve(CollectTokenInterface::class)),
            'tags' => 'store,cms_b',
            'base' => $this->design()->base(),
            'list' => $urls,
        ]);

        self::assertFalse($result);
        self::assertSame([], $this->storedList());
        self::assertSame($bundlesBefore, $this->filesIn($bundleDirectory));
        self::assertTrue($this->claim(), 'A refused report took the route key\'s hold.');
    }

    /**
     * Keys the server did not issue for the route key.
     *
     * @return array<string, array{callable(string, CollectTokenInterface): string}>
     */
    public static function unissuedKeyProvider(): array
    {
        return [
            'plain route key' => [static fn (string $routeKey): string => $routeKey],
            'tampered signature' => [
                static fn (string $routeKey, CollectTokenInterface $token): string => substr(
                    $token->issue($routeKey),
                    0,
                    -1
                ) . (str_ends_with($token->issue($routeKey), '0') ? '1' : '0'),
            ],
            'signature of another route key' => [
                static function (string $routeKey, CollectTokenInterface $token): string {
                    $issued = $token->issue('another_route_key___1');

                    return $routeKey . substr($issued, (int)strrpos($issued, '.'));
                },
            ],
            'missing dot' => [
                static fn (string $routeKey, CollectTokenInterface $token): string => str_replace(
                    '.',
                    '',
                    $token->issue($routeKey)
                ),
            ],
            'empty' => [static fn (): string => ''],
        ];
    }

    /**
     * A request whose key, base or list has the wrong shape is refused with a JSON answer, not an error.
     *
     * @dataProvider malformedRequestProvider
     * @param array<string, mixed> $replacements Parameters that replace the well-formed ones
     * @return void
     */
    public function testAMalformedRequestIsRefusedWithoutAnError(array $replacements): void
    {
        $params = array_replace($this->validParams($this->fixtureUrls(1)), $replacements);

        self::assertFalse($this->collect($params));
        self::assertSame([], $this->storedList());
    }

    /**
     * Requests of the wrong shape.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedRequestProvider(): array
    {
        return [
            'key is an array' => [['key' => ['x']]],
            'base is an array' => [['base' => ['x']]],
            'list is a string' => [['list' => 'https://example.test/a.js']],
            'list holds an array' => [['list' => [['nested']]]],
        ];
    }

    /**
     * A token for a route key of another store is refused.
     *
     * @return void
     */
    public function testARouteKeyOfAnotherStoreIsRefused(): void
    {
        $this->routeKey = $this->design()->routeKey('pagespeed_collect', '999');

        self::assertFalse($this->collect($this->validParams($this->fixtureUrls(1))));
        self::assertSame([], $this->storedList());
    }

    /**
     * A base other than the route key's design is refused and leaves no list and no hold.
     *
     * @dataProvider foreignBaseProvider
     * @param callable(DesignFixture, Store): string $foreignBase Builds the base from the route key's design
     * @return void
     */
    public function testABaseOtherThanTheRouteKeysDesignIsRefused(callable $foreignBase): void
    {
        $store = $this->resolve(StoreManagerInterface::class)->getStore();
        self::assertInstanceOf(Store::class, $store);
        $base = $foreignBase($this->design(), $store);
        $params = $this->validParams($this->fixtureUrls(1));
        $params['base'] = $base;
        $params['list'] = [$base . 'Fixture_Module/m1.js'];

        self::assertFalse($this->collect($params));
        self::assertSame([], $this->storedList());
        self::assertTrue($this->claim(), 'A refused report took the route key\'s hold.');
    }

    /**
     * Bases of another theme, locale or area, and outside the static content.
     *
     * @return array<string, array{callable(DesignFixture, Store): string}>
     */
    public static function foreignBaseProvider(): array
    {
        return [
            'another theme' => [
                static fn (DesignFixture $design): string => $design->staticRootUrl() . 'frontend/Pagespeed/other/'
                    . basename($design->base()) . '/',
            ],
            'another locale' => [
                static fn (DesignFixture $design): string => dirname($design->base()) . '/xx_XX/',
            ],
            'admin area' => [
                static fn (DesignFixture $design): string => $design->staticRootUrl() . 'adminhtml/Magento/backend/'
                    . basename($design->base()) . '/',
            ],
            'static root' => [static fn (DesignFixture $design): string => $design->staticRootUrl()],
            'web root' => [static fn (DesignFixture $design, Store $store): string => $store->getBaseUrl()],
        ];
    }

    /**
     * A first report stores its scripts and publishes the bundle of the new list, which inlines each script under
     * its module key; the page cache tags it carries change nothing.
     *
     * @return void
     */
    public function testAFirstReportStoresItsListAndPublishesItsBundle(): void
    {
        $this->throttleNever();
        $urls = $this->fixtureUrls(2);

        self::assertTrue($this->collect($this->validParams($urls)));

        self::assertSame($urls, $this->storedList());
        $bundlePath = $this->rememberBundlePath();
        self::assertFileExists($bundlePath);
        $scripts = BundleModules::scripts((string)file_get_contents($bundlePath));
        self::assertStringContainsString('return 1', $scripts[$this->design()->moduleKey('m1.js')] ?? '');
        self::assertStringContainsString('return 2', $scripts[$this->design()->moduleKey('m2.js')] ?? '');
    }

    /**
     * A list of the size a checkout page reports is stored over successive reports, a limited number at a time.
     *
     * @return void
     */
    public function testAListOfCheckoutSizeIsStoredOverSuccessiveReports(): void
    {
        $this->throttleNever();
        $params = $this->validParams($this->fixtureUrls(505));

        self::assertTrue($this->collect($params));
        self::assertCount(200, $this->storedList());
        self::assertTrue($this->collect($params));
        self::assertCount(400, $this->storedList());
        self::assertTrue($this->collect($params));
        self::assertCount(505, $this->storedList());
    }

    /**
     * A report larger than the largest lists stores hold today is accepted.
     *
     * @return void
     */
    public function testAReportLargerThanTodaysLargestListsIsAccepted(): void
    {
        $this->throttleNever();

        self::assertTrue($this->collect($this->validParams($this->fixtureUrls(1500))));
        self::assertCount(200, $this->storedList());
    }

    /**
     * A report longer than the limit is refused and stores nothing.
     *
     * @return void
     */
    public function testAReportLongerThanTheLimitIsRefused(): void
    {
        $this->throttleNever();
        $urls = $this->fixtureUrls(1);
        $urls = array_merge($urls, array_fill(0, 3000, $urls[0]));

        self::assertFalse($this->collect($this->validParams($urls)));
        self::assertSame([], $this->storedList());
    }

    /**
     * Entries that are not existing scripts or templates of the design under the base never enter the list.
     *
     * @return void
     */
    public function testOnlyExistingScriptsAndTemplatesOfTheDesignAreStored(): void
    {
        $this->throttleNever();
        $script = $this->fixtureUrls(1)[0];
        $template = $this->design()->moduleUrl('template/view.html', '<div></div>');
        $style = $this->design()->moduleUrl('css/style.css', 'a{}');
        $base = $this->design()->base();
        $store = $this->resolve(StoreManagerInterface::class)->getStore();
        self::assertInstanceOf(Store::class, $store);

        $result = $this->collect($this->validParams([
            $script,
            $template,
            $style,
            $script . '?v=2',
            $script . '#x',
            $base . $this->design()->moduleKey('../' . basename(dirname($script)) . '/m1.js'),
            $base . $this->design()->moduleKey('missing.js'),
            $this->design()->staticRootUrl() . 'adminhtml/Magento/backend/en_US/' . $this->design()->moduleKey('m1.js'),
            $store->getBaseUrl(UrlInterface::URL_TYPE_MEDIA) . 'logo.js',
            $store->getBaseUrl(UrlInterface::URL_TYPE_WEB) . 'app/etc/env.js',
        ]));

        self::assertTrue($result);
        self::assertSame([$script, $template], $this->storedList());
    }

    /**
     * A stored module is never replaced: a report naming the same module by another URL only adds new modules.
     *
     * @return void
     */
    public function testAStoredModuleIsNeverReplaced(): void
    {
        $this->throttleNever();
        $urls = $this->fixtureUrls(2);
        $otherScheme = str_starts_with($urls[0], 'https:')
            ? 'http:' . substr($urls[0], 6)
            : 'https:' . substr($urls[0], 5);
        self::assertTrue(
            $this->resolve(RequireJsManager::class)->saveUrlList([$otherScheme], $this->routeKey())
        );

        self::assertTrue($this->collect($this->validParams($urls)));

        self::assertSame([$otherScheme, $urls[1]], $this->storedList());
    }

    /**
     * A report in which nothing qualifies never takes the hold, so a valid report right after it still adds.
     *
     * @return void
     */
    public function testAJunkReportNeverTakesTheHold(): void
    {
        $style = $this->design()->moduleUrl('css/style.css', 'a{}');
        $script = $this->fixtureUrls(1)[0];

        self::assertTrue($this->collect($this->validParams([$style])));
        self::assertTrue($this->collect($this->validParams([$script])));

        self::assertSame([$script], $this->storedList());
    }

    /**
     * Within the hold period, a second report with new modules is accepted but changes nothing.
     *
     * @return void
     */
    public function testASecondReportWithinTheHoldPeriodChangesNothing(): void
    {
        $urls = $this->fixtureUrls(2);

        self::assertTrue($this->collect($this->validParams([$urls[0]])));
        self::assertTrue($this->collect($this->validParams([$urls[1]])));

        self::assertSame([$urls[0]], $this->storedList());
    }

    /**
     * No report deletes or rewrites an existing bundle file or changes the last-update marker: neither one that
     * adds nothing, nor one that adds URLs and so gets a bundle file of its own.
     *
     * @return void
     */
    public function testNoReportDeletesOrRewritesAnExistingBundleFile(): void
    {
        $this->throttleNever();
        $urls = $this->fixtureUrls(3);
        $lastUpdate = $this->lastUpdate();

        self::assertTrue($this->collect($this->validParams([$urls[0], $urls[1]])));
        $firstBundle = $this->rememberBundlePath();
        touch($firstBundle, time() - 3600);
        $before = $this->fileIdentity($firstBundle);

        self::assertTrue($this->collect($this->validParams([$urls[1]])));
        self::assertSame($before, $this->fileIdentity($firstBundle), 'A report that adds nothing touched the bundle.');
        self::assertSame([$urls[0], $urls[1]], $this->storedList());

        self::assertTrue($this->collect($this->validParams([$urls[2]])));
        $secondBundle = $this->rememberBundlePath();
        self::assertNotSame($firstBundle, $secondBundle);
        self::assertFileExists($secondBundle);
        self::assertSame($before, $this->fileIdentity($firstBundle), 'A report that adds URLs touched the old bundle.');
        self::assertSame($lastUpdate, $this->lastUpdate());
    }

    /**
     * A report that adds nothing recreates the bundle file of the stored list when it is missing, and saves nothing.
     *
     * @return void
     */
    public function testAReportThatAddsNothingRecreatesAMissingBundle(): void
    {
        $this->throttleNever();
        $urls = $this->fixtureUrls(2);
        self::assertTrue($this->collect($this->validParams($urls)));
        $bundlePath = $this->rememberBundlePath();
        unlink($bundlePath);

        self::assertTrue($this->collect($this->validParams([$urls[0]])));

        self::assertFileExists($bundlePath);
        self::assertSame($urls, $this->storedList());
    }

    /**
     * Replace the throttle with one that never holds, so one test can report several times in a row.
     *
     * @return void
     */
    private function throttleNever(): void
    {
        $objectManager = $this->objectManager();
        $objectManager->removeSharedInstance(RecordCollectedUrls::class);
        $objectManager->addSharedInstance(
            new class implements CollectThrottleInterface {
                /**
                 * @inheritDoc
                 */
                public function claim(string $routeKey): bool
                {
                    return true;
                }
            },
            CollectThrottle::class
        );
    }

    /**
     * Claim the route key on the real throttle; true when no report holds it.
     *
     * @return bool
     */
    private function claim(): bool
    {
        $throttle = $this->objectManager()->create(CollectThrottle::class);
        self::assertInstanceOf(CollectThrottle::class, $throttle);

        return $throttle->claim($this->routeKey());
    }

    /**
     * Dispatch a collect request and return the result it answered.
     *
     * @param array<string, mixed> $params Post parameters
     * @return bool|null The "result" of the JSON answer, or null when the answer is not that JSON
     */
    private function collect(array $params): ?bool
    {
        $this->resetRequest();

        $request = $this->request();
        $request->setMethod('POST');
        $request->setPostValue($params);
        $headers = $request->getHeaders();
        self::assertInstanceOf(Headers::class, $headers);
        $headers->addHeaderLine('X-Requested-With', 'XMLHttpRequest');

        $this->dispatch('pagespeedjsmerge/js/collect');

        $response = $this->getResponse();
        self::assertInstanceOf(HttpResponse::class, $response);
        $answer = json_decode((string)$response->getBody(), true);

        return is_array($answer) && is_bool($answer['result'] ?? null) ? $answer['result'] : null;
    }

    /**
     * Well-formed parameters reporting the URLs under this test's token.
     *
     * @param list<string> $urls Module URLs
     * @return array{key: string, tags: string, base: string, list: list<string>}
     */
    private function validParams(array $urls): array
    {
        return [
            'key' => $this->resolve(CollectTokenInterface::class)->issue($this->routeKey()),
            'tags' => 'store,cms_b,cat_c',
            'base' => $this->design()->base(),
            'list' => $urls,
        ];
    }

    /**
     * Create module scripts of the fixture module and return their URLs.
     *
     * @param int $count How many scripts
     * @return non-empty-list<string>
     */
    private function fixtureUrls(int $count): array
    {
        $urls = [];
        for ($i = 1; $i <= max(1, $count); $i++) {
            $urls[] = $this->design()->moduleUrl('m' . $i . '.js', 'define([], function () { return ' . $i . '; });');
        }

        return $urls;
    }

    /**
     * The URL list stored under this test's route key.
     *
     * @return array<mixed>
     */
    private function storedList(): array
    {
        return $this->resolve(RequireJsManager::class)->loadUrlList($this->routeKey());
    }

    /**
     * Path of the bundle file for the stored list, remembered for removal.
     *
     * @return string
     */
    private function rememberBundlePath(): string
    {
        $path = $this->resolve(MergeJs::class)->getRequireJsResultFilePath($this->routeKey());
        $this->bundlePaths?->append($path);

        return $path;
    }

    /**
     * The value of the last-update marker bundle URLs carry.
     *
     * @return mixed
     */
    private function lastUpdate(): mixed
    {
        return $this->resolve(JsList::class)->getCache()->load('last_update');
    }

    /**
     * Inode and modification time of a file.
     *
     * @param string $path File path
     * @return array{int, int}
     */
    private function fileIdentity(string $path): array
    {
        clearstatcache();
        $stat = stat($path);
        self::assertIsArray($stat);

        return [$stat['ino'], $stat['mtime']];
    }

    /**
     * Names of the files in a directory.
     *
     * @param string $directory Directory path
     * @return list<string>
     */
    private function filesIn(string $directory): array
    {
        return is_dir($directory) ? array_values(array_diff(scandir($directory) ?: [], ['.', '..'])) : [];
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
