<?php
/**
 * Copyright (c) 2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\BundleFileWriterInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectedUrlFilterInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectThrottleInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectTokenInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\DesignBaseUrlResolverInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\RecordCollectedUrlsInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\RequireJsBundleLocatorInterface;

/**
 * Checks run cheapest first and nothing is written before all of them passed: an oversized report, a token the
 * server did not issue, a route key of another store and a base other than the route key's design are refused.
 * The route key's hold is taken only by a report that brings modules the stored list lacks, so a report without
 * anything new, valid or not, never keeps another report from adding modules.
 *
 * Stored entries come first and the first URL for a module key wins: a report can add modules, never replace one.
 * A report adds at most a fixed number of new modules; the rest wait for a later report.
 *
 * The page cache is never purged. A merged bundle file is named after the URL list it holds, so a cached page keeps
 * loading the file it references, and a module missing from that file is fetched on its own; a richer bundle reaches
 * pages as they are rendered again. For the same reason no bundle file is deleted or rewritten here: a list that
 * gains URLs gets a file of its own, written only when absent.
 */
class RecordCollectedUrls implements RecordCollectedUrlsInterface
{
    /**
     * @param CollectTokenInterface $collectToken Recovers the route key from the token
     * @param DesignBaseUrlResolverInterface $designBaseUrlResolver Names the base URLs of the route key's design
     * @param CollectedUrlFilterInterface $urlFilter Decides which URLs may enter a list and weighs a list
     * @param CollectThrottleInterface $throttle Lets one report per route key work within the hold period
     * @param RequireJsManager $requireJsManager Stores the URL lists and builds bundle content
     * @param RequireJsBundleLocatorInterface $bundleLocator Names the bundle file of the stored list
     * @param BundleFileWriterInterface $bundleFileWriter Publishes a bundle file that does not exist yet
     * @param int $maxUrlCount Most URLs a report may carry and a stored list may hold
     * @param int $maxNewUrlsPerReport Most modules one report may add to a list
     * @param int $maxTotalBytes Most bytes the files of a stored list may add up to
     */
    public function __construct(
        private readonly CollectTokenInterface $collectToken,
        private readonly DesignBaseUrlResolverInterface $designBaseUrlResolver,
        private readonly CollectedUrlFilterInterface $urlFilter,
        private readonly CollectThrottleInterface $throttle,
        private readonly RequireJsManager $requireJsManager,
        private readonly RequireJsBundleLocatorInterface $bundleLocator,
        private readonly BundleFileWriterInterface $bundleFileWriter,
        private readonly int $maxUrlCount = 3000,
        private readonly int $maxNewUrlsPerReport = 200,
        private readonly int $maxTotalBytes = 20971520
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(string $token, string $base, array $urls): bool
    {
        if (count($urls) > $this->maxUrlCount) {
            return false;
        }

        $routeKey = $this->collectToken->routeKeyOf($token);
        if ($routeKey === null
            || !$this->urlFilter->acceptsBase($this->designBaseUrlResolver->forRouteKey($routeKey), $base)
        ) {
            return false;
        }

        $reported = $this->urlFilter->filterReported($base, $urls);
        if ($reported === []) {
            return true;
        }

        $storedUrls = $this->storedUrls($routeKey);
        $stored = $this->urlFilter->filterStored($base, $storedUrls);
        $added = array_slice(array_diff_key($reported, $stored), 0, $this->maxNewUrlsPerReport, true);
        if ($added === []) {
            if ($storedUrls !== []) {
                $this->publishBundle($routeKey);
            }

            return true;
        }

        if (!$this->throttle->claim($routeKey)) {
            return true;
        }

        $merged = $stored + $added;
        if (count($merged) > $this->maxUrlCount
            || $this->urlFilter->totalBytes($base, array_map('strval', array_keys($merged))) > $this->maxTotalBytes
            || !$this->requireJsManager->saveUrlList(array_values($merged), $routeKey)
        ) {
            return false;
        }

        $this->publishBundle($routeKey);

        return true;
    }

    /**
     * The URL list stored under the route key, without anything that is not a string.
     *
     * @param string $routeKey Route key
     * @return list<string>
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    private function storedUrls(string $routeKey): array
    {
        $urls = [];
        foreach ($this->requireJsManager->loadUrlList($routeKey) as $url) {
            if (is_string($url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Write the bundle file of the stored list when it does not exist yet.
     *
     * @param string $routeKey Route key
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    private function publishBundle(string $routeKey): void
    {
        $this->bundleFileWriter->writeIfAbsent(
            $this->bundleLocator->getRequireJsResultFilePath($routeKey),
            fn (): string => $this->requireJsManager->getRequireJsContent($routeKey)
        );
    }
}
