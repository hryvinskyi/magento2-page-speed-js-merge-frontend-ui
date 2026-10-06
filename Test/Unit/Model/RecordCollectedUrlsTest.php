<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Test\Unit\Model;

use ArrayObject;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\BundleFileWriterInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectedUrlFilterInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectThrottleInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectTokenInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\DesignBaseUrlResolverInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\RequireJsBundleLocatorInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\RecordCollectedUrls;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A report is checked before anything is written, takes the route key's hold only when it brings new modules, adds
 * modules without replacing any, and only ever adds a bundle file that does not exist yet.
 */
class RecordCollectedUrlsTest extends TestCase
{
    private const ROUTE_KEY = 'catalog_product_view____catalog_product_view_type_simple___abc___1';
    private const TOKEN = 'valid-token';
    private const BASE = 'https://shop.test/static/frontend/Vendor/theme/en_US/';
    private const BUNDLE_PATH = '/pub/static/pagespeed_cache/requirejs/bundle.js';
    private const WRITE = 'write ' . self::BUNDLE_PATH . ' bundle content';

    /** @var ArrayObject<int, string> Calls to collaborators, in order */
    private ArrayObject $log;

    /** @var RequireJsManager&MockObject */
    private RequireJsManager $requireJsManager;

    /** @var list<string> */
    private array $storedList = [];

    /** @var list<list<string>> Lists the use case saved */
    private array $savedLists = [];

    private bool $claimSucceeds = true;

    private bool $designKnown = true;

    private int $bytesPerFile = 1;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->log = new ArrayObject();
        $this->requireJsManager = $this->createMock(RequireJsManager::class);
        $this->requireJsManager->method('loadUrlList')->willReturnCallback(function (?string $routeKey): array {
            $this->log[] = 'load ' . $routeKey;

            return $this->storedList;
        });
        $this->requireJsManager->method('saveUrlList')->willReturnCallback(
            function (array $list, string $routeKey): bool {
                $this->log[] = 'save ' . $routeKey;
                $this->savedLists[] = array_values(array_filter($list, 'is_string'));

                return true;
            }
        );
        $this->requireJsManager->method('getRequireJsContent')->willReturn('bundle content');
    }

    /**
     * A report longer than the limit is refused before anything else happens.
     *
     * @return void
     */
    public function testAReportLongerThanTheLimitIsRefusedAndWritesNothing(): void
    {
        $result = $this->useCase(maxUrlCount: 2)->execute(self::TOKEN, self::BASE, $this->urls('a', 'b', 'c'));

        self::assertFalse($result);
        self::assertSame([], $this->log->getArrayCopy());
    }

    /**
     * A key the server did not issue is refused without reading or holding anything.
     *
     * @return void
     */
    public function testAnUnissuedKeyIsRefusedAndWritesNothing(): void
    {
        $result = $this->useCase()->execute(self::ROUTE_KEY, self::BASE, $this->urls('a'));

        self::assertFalse($result);
        self::assertSame(['routeKeyOf ' . self::ROUTE_KEY], $this->log->getArrayCopy());
    }

    /**
     * A route key whose design cannot be resolved, as for another store, is refused without reading anything.
     *
     * @return void
     */
    public function testARouteKeyWithoutAKnownDesignIsRefused(): void
    {
        $this->designKnown = false;

        $result = $this->useCase()->execute(self::TOKEN, self::BASE, $this->urls('a'));

        self::assertFalse($result);
        self::assertSame(['routeKeyOf ' . self::TOKEN, 'design', 'acceptsBase'], $this->log->getArrayCopy());
    }

    /**
     * A base other than the route key's design is refused without reading or holding anything.
     *
     * @return void
     */
    public function testABaseOtherThanTheDesignIsRefused(): void
    {
        $result = $this->useCase()->execute(self::TOKEN, 'https://shop.test/', ['https://shop.test/a.js']);

        self::assertFalse($result);
        self::assertSame(['routeKeyOf ' . self::TOKEN, 'design', 'acceptsBase'], $this->log->getArrayCopy());
    }

    /**
     * A report in which nothing qualifies is accepted without reading the list or taking the hold.
     *
     * @return void
     */
    public function testAJunkReportNeverTakesTheHold(): void
    {
        $result = $this->useCase()->execute(self::TOKEN, self::BASE, [self::BASE . 'style.css']);

        self::assertTrue($result);
        self::assertSame([], $this->matching('/^(claim|load|save|write) /'));
    }

    /**
     * A report that brings nothing new saves nothing and never takes the hold; only a missing bundle file of the
     * stored list may be written.
     *
     * @return void
     */
    public function testAReportWithNothingNewNeverTakesTheHold(): void
    {
        $this->storedList = $this->urls('a', 'b');

        $result = $this->useCase()->execute(self::TOKEN, self::BASE, $this->urls('b'));

        self::assertTrue($result);
        self::assertSame([], $this->savedLists);
        self::assertSame([], $this->matching('/^claim /'));
        self::assertContains(self::WRITE, $this->log->getArrayCopy());
    }

    /**
     * While the route key is held, a report with new modules is accepted without writing anything.
     *
     * @return void
     */
    public function testAReportWithNewModulesForAHeldRouteKeyWritesNothing(): void
    {
        $this->claimSucceeds = false;
        $this->storedList = $this->urls('a');

        $result = $this->useCase()->execute(self::TOKEN, self::BASE, $this->urls('b'));

        self::assertTrue($result);
        self::assertSame([], $this->savedLists);
        self::assertSame([], $this->matching('/^write /'));
    }

    /**
     * The hold is taken before the list is saved.
     *
     * @return void
     */
    public function testTheHoldIsTakenBeforeAnythingIsWritten(): void
    {
        $this->useCase()->execute(self::TOKEN, self::BASE, $this->urls('a'));

        $log = $this->log->getArrayCopy();
        $claim = array_search('claim ' . self::ROUTE_KEY, $log, true);
        $save = array_search('save ' . self::ROUTE_KEY, $log, true);
        self::assertIsInt($claim);
        self::assertIsInt($save);
        self::assertLessThan($save, $claim);
    }

    /**
     * A first list is stored and its bundle published after the list is saved.
     *
     * @return void
     */
    public function testAFirstListIsStoredAndItsBundlePublished(): void
    {
        $urls = array_map(static fn (int $i): string => self::BASE . 'm' . $i . '.js', range(1, 150));

        self::assertTrue($this->useCase()->execute(self::TOKEN, self::BASE, $urls));
        self::assertSame([$urls], $this->savedLists);
        $log = $this->log->getArrayCopy();
        self::assertLessThan(array_search(self::WRITE, $log, true), array_search('save ' . self::ROUTE_KEY, $log, true));
    }

    /**
     * One report adds at most the configured number of new modules; the rest are left for later reports.
     *
     * @return void
     */
    public function testAReportAddsAtMostTheConfiguredNumberOfNewModules(): void
    {
        $this->storedList = $this->urls('a');

        $this->useCase(maxNewUrlsPerReport: 2)->execute(self::TOKEN, self::BASE, $this->urls('a', 'b', 'c', 'd'));

        self::assertSame([$this->urls('a', 'b', 'c')], $this->savedLists);
    }

    /**
     * A stored module is never replaced by a reported URL for the same module key; stale stored entries go.
     *
     * @return void
     */
    public function testAStoredModuleIsNeverReplaced(): void
    {
        $this->storedList = ['https://shop.test/static/version1/frontend/Vendor/theme/en_US/a.js', self::BASE . 'gone.js'];

        $this->useCase()->execute(self::TOKEN, self::BASE, $this->urls('a', 'b'));

        self::assertSame(
            [['https://shop.test/static/version1/frontend/Vendor/theme/en_US/a.js', self::BASE . 'b.js']],
            $this->savedLists
        );
    }

    /**
     * An addition that would grow the stored list past the URL limit is refused and writes nothing.
     *
     * @return void
     */
    public function testAStoredListNeverGrowsPastTheUrlLimit(): void
    {
        $this->storedList = $this->urls('a', 'b');

        $result = $this->useCase(maxUrlCount: 2)->execute(self::TOKEN, self::BASE, $this->urls('c'));

        self::assertFalse($result);
        self::assertSame([], $this->savedLists);
        self::assertSame([], $this->matching('/^write /'));
    }

    /**
     * An addition that would make the listed files exceed the size limit is refused and writes nothing.
     *
     * @return void
     */
    public function testAStoredListNeverGrowsPastTheSizeLimit(): void
    {
        $this->storedList = $this->urls('a', 'b');
        $this->bytesPerFile = 10;

        $result = $this->useCase(maxTotalBytes: 25)->execute(self::TOKEN, self::BASE, $this->urls('c'));

        self::assertFalse($result);
        self::assertSame([], $this->savedLists);
        self::assertSame([], $this->matching('/^write /'));
    }

    /**
     * A list that cannot be saved is reported as refused, and no bundle is written for it.
     *
     * @return void
     */
    public function testAListThatCannotBeSavedWritesNoBundle(): void
    {
        $requireJsManager = $this->createMock(RequireJsManager::class);
        $requireJsManager->method('loadUrlList')->willReturn([]);
        $requireJsManager->method('saveUrlList')->willReturn(false);
        $this->requireJsManager = $requireJsManager;

        $result = $this->useCase()->execute(self::TOKEN, self::BASE, $this->urls('a'));

        self::assertFalse($result);
        self::assertSame([], $this->matching('/^write /'));
    }

    /**
     * Module URLs under the base for the given names.
     *
     * @param string ...$names Module names without suffix
     * @return list<string>
     */
    private function urls(string ...$names): array
    {
        return array_values(array_map(static fn (string $name): string => self::BASE . $name . '.js', $names));
    }

    /**
     * Logged calls that match a pattern.
     *
     * @param string $pattern Regular expression
     * @return list<string>
     */
    private function matching(string $pattern): array
    {
        return array_values(array_filter(
            $this->log->getArrayCopy(),
            static fn (string $entry): bool => preg_match($pattern, $entry) === 1
        ));
    }

    /**
     * The use case on recording fakes of its ports.
     *
     * @param int $maxUrlCount Most URLs a report and a stored list may hold
     * @param int $maxNewUrlsPerReport Most modules one report may add
     * @param int $maxTotalBytes Most bytes the listed files may add up to
     * @return RecordCollectedUrls
     */
    private function useCase(
        int $maxUrlCount = 3000,
        int $maxNewUrlsPerReport = 200,
        int $maxTotalBytes = 20971520
    ): RecordCollectedUrls {
        return new RecordCollectedUrls(
            $this->collectToken(),
            $this->designBaseUrlResolver(),
            $this->urlFilter(),
            $this->throttle(),
            $this->requireJsManager,
            $this->bundleLocator(),
            $this->bundleFileWriter(),
            $maxUrlCount,
            $maxNewUrlsPerReport,
            $maxTotalBytes
        );
    }

    /**
     * A token service that knows one token.
     *
     * @return CollectTokenInterface
     */
    private function collectToken(): CollectTokenInterface
    {
        return new class ($this->log) implements CollectTokenInterface {
            /**
             * @param ArrayObject<int, string> $log
             */
            public function __construct(private readonly ArrayObject $log)
            {
            }

            /**
             * @inheritDoc
             */
            public function issue(string $routeKey): string
            {
                return RecordCollectedUrlsTest::validToken();
            }

            /**
             * @inheritDoc
             */
            public function routeKeyOf(string $token): ?string
            {
                $this->log[] = 'routeKeyOf ' . $token;

                return $token === RecordCollectedUrlsTest::validToken() ? RecordCollectedUrlsTest::routeKey() : null;
            }
        };
    }

    /**
     * A resolver that knows the base's design, or none.
     *
     * @return DesignBaseUrlResolverInterface
     */
    private function designBaseUrlResolver(): DesignBaseUrlResolverInterface
    {
        return new class ($this->log, $this->designKnown) implements DesignBaseUrlResolverInterface {
            /**
             * @param ArrayObject<int, string> $log
             * @param bool $designKnown
             */
            public function __construct(private readonly ArrayObject $log, private readonly bool $designKnown)
            {
            }

            /**
             * @inheritDoc
             */
            public function forRouteKey(string $routeKey): array
            {
                $this->log[] = 'design';

                return $this->designKnown ? [RecordCollectedUrlsTest::base()] : [];
            }
        };
    }

    /**
     * A filter that keeps ".js" URLs not named "gone", keyed by file name, the first one per key.
     *
     * @return CollectedUrlFilterInterface
     */
    private function urlFilter(): CollectedUrlFilterInterface
    {
        return new class ($this->log, $this->bytesPerFile) implements CollectedUrlFilterInterface {
            /**
             * @param ArrayObject<int, string> $log
             * @param int $bytesPerFile
             */
            public function __construct(private readonly ArrayObject $log, private readonly int $bytesPerFile)
            {
            }

            /**
             * @inheritDoc
             */
            public function acceptsBase(array $designBaseUrls, string $base): bool
            {
                $this->log[] = 'acceptsBase';

                return in_array($base, $designBaseUrls, true);
            }

            /**
             * @inheritDoc
             */
            public function filterReported(string $base, array $urls): array
            {
                return $this->keep($urls);
            }

            /**
             * @inheritDoc
             */
            public function filterStored(string $base, array $urls): array
            {
                return $this->keep($urls);
            }

            /**
             * @inheritDoc
             */
            public function totalBytes(string $base, array $moduleKeys): int
            {
                return count($moduleKeys) * $this->bytesPerFile;
            }

            /**
             * @param list<string> $urls
             * @return array<string, string>
             */
            private function keep(array $urls): array
            {
                $kept = [];
                foreach ($urls as $url) {
                    $moduleKey = basename($url);
                    if (str_ends_with($url, '.js') && $moduleKey !== 'gone.js' && !isset($kept[$moduleKey])) {
                        $kept[$moduleKey] = $url;
                    }
                }

                return $kept;
            }
        };
    }

    /**
     * A throttle that grants or refuses every claim.
     *
     * @return CollectThrottleInterface
     */
    private function throttle(): CollectThrottleInterface
    {
        return new class ($this->log, $this->claimSucceeds) implements CollectThrottleInterface {
            /**
             * @param ArrayObject<int, string> $log
             * @param bool $claimSucceeds
             */
            public function __construct(private readonly ArrayObject $log, private readonly bool $claimSucceeds)
            {
            }

            /**
             * @inheritDoc
             */
            public function claim(string $routeKey): bool
            {
                $this->log[] = 'claim ' . $routeKey;

                return $this->claimSucceeds;
            }
        };
    }

    /**
     * A locator with one fixed bundle path.
     *
     * @return RequireJsBundleLocatorInterface
     */
    private function bundleLocator(): RequireJsBundleLocatorInterface
    {
        return new class implements RequireJsBundleLocatorInterface {
            /**
             * @inheritDoc
             */
            public function getRequireJsResultFilePath(string $key): string
            {
                return '/pub/static/pagespeed_cache/requirejs/bundle.js';
            }
        };
    }

    /**
     * A writer that records what it was asked to write.
     *
     * @return BundleFileWriterInterface
     */
    private function bundleFileWriter(): BundleFileWriterInterface
    {
        return new class ($this->log) implements BundleFileWriterInterface {
            /**
             * @param ArrayObject<int, string> $log
             */
            public function __construct(private readonly ArrayObject $log)
            {
            }

            /**
             * @inheritDoc
             */
            public function writeIfAbsent(string $path, callable $content): void
            {
                $this->log[] = 'write ' . $path . ' ' . $content();
            }
        };
    }

    /**
     * The token the fake token service accepts.
     *
     * @return string
     */
    public static function validToken(): string
    {
        return self::TOKEN;
    }

    /**
     * The route key the accepted token stands for.
     *
     * @return string
     */
    public static function routeKey(): string
    {
        return self::ROUTE_KEY;
    }

    /**
     * The base of the route key's design.
     *
     * @return string
     */
    public static function base(): string
    {
        return self::BASE;
    }
}
