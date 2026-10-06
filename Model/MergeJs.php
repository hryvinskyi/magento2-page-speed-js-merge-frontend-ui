<?php
/**
 * Copyright (c) 2025-2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeed\Model\Cache as PageSpeedCache;
use Hryvinskyi\PageSpeed\Model\GetLastFileChangeTimestampForUrlList;
use Hryvinskyi\PageSpeedApi\Api\Finder\JsInterface as JsFinderInterface;
use Hryvinskyi\PageSpeedApi\Api\Finder\Result\TagInterface;
use Hryvinskyi\PageSpeedApi\Api\GetFileContentByUrlInterface;
use Hryvinskyi\PageSpeedApi\Api\GetLocalPathFromUrlInterface;
use Hryvinskyi\PageSpeedApi\Api\GetRequireJsBuildScriptUrlInterface;
use Hryvinskyi\PageSpeedApi\Api\GetStringLengthFromUrlInterface;
use Hryvinskyi\PageSpeedApi\Api\Html\GetStringFromHtmlInterface;
use Hryvinskyi\PageSpeedApi\Api\Html\IsTagMustBeIgnoredInterface;
use Hryvinskyi\PageSpeedApi\Api\Html\ReplaceIntoHtmlInterface;
use Hryvinskyi\PageSpeedApi\Api\IsInternalUrlInterface;
use Hryvinskyi\PageSpeedApi\Model\CacheInterface;
use Hryvinskyi\PageSpeedJsMerge\Api\ConfigInterface;
use Hryvinskyi\PageSpeedJsMerge\Api\MergeJsInterface;
use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\BundleFileWriterInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\MergeProcessorPoolInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\RequireJsBundleLocatorInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\Merger\Js as JsMerger;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Exception\NoSuchEntityException;

class MergeJs implements MergeJsInterface, RequireJsBundleLocatorInterface
{
    private const IGNORE_MERGE_FLAG = 'ignore_merge';

    /**
     * @param ConfigInterface $config
     * @param CacheInterface $cache
     * @param ResponseInterface $response
     * @param RequestInterface $request
     * @param RequireJsManager $requireJsManager
     * @param JsFinderInterface $jsFinder
     * @param ReplaceIntoHtmlInterface $replaceIntoHtml
     * @param BundleFileWriterInterface $bundleFileWriter
     * @param IsTagMustBeIgnoredInterface $isTagMustBeIgnored
     * @param IsInternalUrlInterface $isInternalUrl
     * @param GetStringLengthFromUrlInterface $getStringLengthFromUrl
     * @param GetFileContentByUrlInterface $getFileContentByUrl
     * @param GetLastFileChangeTimestampForUrlList $getLastFileChangeTimestampForUrlList
     * @param GetRequireJsBuildScriptUrlInterface $getRequireJsBuildScriptUrl
     * @param GetStringFromHtmlInterface $getStringFromHtml
     * @param GetLocalPathFromUrlInterface $getLocalPathFromUrl
     * @param JsMerger $jsMerger
     * @param JsList $jsListCache
     * @param MergeProcessorPoolInterface $mergeProcessorPool
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly CacheInterface $cache,
        private readonly ResponseInterface $response,
        private readonly RequestInterface $request,
        private readonly RequireJsManager $requireJsManager,
        private readonly JsFinderInterface $jsFinder,
        private readonly ReplaceIntoHtmlInterface $replaceIntoHtml,
        private readonly BundleFileWriterInterface $bundleFileWriter,
        private readonly IsTagMustBeIgnoredInterface $isTagMustBeIgnored,
        private readonly IsInternalUrlInterface $isInternalUrl,
        private readonly GetStringLengthFromUrlInterface $getStringLengthFromUrl,
        private readonly GetFileContentByUrlInterface $getFileContentByUrl,
        private readonly GetLastFileChangeTimestampForUrlList $getLastFileChangeTimestampForUrlList,
        private readonly GetRequireJsBuildScriptUrlInterface $getRequireJsBuildScriptUrl,
        private readonly GetStringFromHtmlInterface $getStringFromHtml,
        private readonly GetLocalPathFromUrlInterface $getLocalPathFromUrl,
        private readonly JsMerger $jsMerger,
        private readonly JsList $jsListCache,
        private readonly MergeProcessorPoolInterface $mergeProcessorPool
    ) {
    }

    /**
     * @inheritDoc
     */
    public function inline(string &$html): void
    {
        $tagList = $this->jsFinder->findExternal($html);
        $this->excludeIgnoredTags($tagList);
        if (empty($tagList)) {
            return;
        }

        $replaceData = [];
        foreach ($tagList as $tag) {
            if (isset($tag->getAttributes()[self::FLAG_IGNORE_MERGE])) {
                continue;
            }

            $src = $this->getSrc($tag);
            if ($src === null || !$this->isInternalUrl->execute($src)) {
                continue;
            }

            $contentLength = $this->getStringLengthFromUrl->execute($src);
            if ($contentLength > $this->config->getInlineMaxLength()) {
                continue;
            }

            $content = $this->getFileContentByUrl->execute($src);
            if ($content === '' || strlen($content) > $this->config->getInlineMaxLength()) {
                continue;
            }

            $replaceData[] = [
                'start' => $tag->getStart(),
                'end' => $tag->getEnd(),
                'content' => '<script type="text/javascript">' . $content . '</script>'
            ];
        }

        foreach (array_reverse($replaceData) as $replaceElData) {
            $html = $this->replaceIntoHtml->execute(
                $html,
                $replaceElData['content'],
                $replaceElData['start'],
                $replaceElData['end']
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function merge(string &$html): void
    {
        $this->insertRequireJsFiles($html);
        $tagList = $this->config->isMergeInlineJsEnabled()
            ? $this->jsFinder->findAll($html)
            : $this->jsFinder->findExternal($html);

        // Remove ignored tags from the list
        $tagList = $this->excludeIgnoredTagsByAttribute($tagList);
        if ($tagList === []) {
            return;
        }

        $replaceData = [];
        foreach ($this->groupTags($tagList, $html) as $group) {
            if (count($group) < 2) {
                continue;
            }

            $mergedUrl = $this->jsMerger->merge($group);
            if ($mergedUrl === null) {
                continue;
            }

            // Process attributes using merge processor pool extension point
            $attributes = $this->mergeProcessorPool->processAttributes([], $mergedUrl);

            $replaceData[] = [
                'start' => $group[0]->getStart(),
                'end' => $group[count($group) - 1]->getEnd(),
                'url' => $this->appendCacheTimestamp($mergedUrl),
                'attributes' => $attributes
            ];
        }

        foreach (array_reverse($replaceData) as $replaceElData) {
            // Build attributes string from processed attributes
            $attributesString = '';
            foreach ($replaceElData['attributes'] as $name => $value) {
                $attributesString .= ' ' . $name . '="' . htmlspecialchars($value) . '"';
            }

            $replacement = '<script type="text/javascript" src="' . $replaceElData['url'] . '"'
                . $attributesString . '></script>';
            $html = $this->replaceIntoHtml->execute(
                $html,
                $replacement,
                $replaceElData['start'],
                $replaceElData['end']
            );
        }
    }

    /**
     * Exclude ignored tags
     *
     * @param TagInterface[] $tagList
     * @return void
     */
    private function excludeIgnoredTags(array &$tagList): void
    {
        foreach ($tagList as $key => $tag) {
            if ($this->isTagMustBeIgnored($tag)) {
                unset($tagList[$key]);
            }
        }
    }

    /**
     * Exclude ignored tags by attribute
     *
     * @param TagInterface[] $tagList
     * @return list<TagInterface> The tags without the ignore-merge attribute, in their order
     */
    private function excludeIgnoredTagsByAttribute(array $tagList): array
    {
        $newTagList = [];
        foreach ($tagList as $tag) {
            if (!array_key_exists(self::FLAG_IGNORE_MERGE, $tag->getAttributes())) {
                $newTagList[] = $tag;
            }
        }

        return $newTagList;
    }

    /**
     * Group consecutive tags that can be merged into one file
     *
     * @param non-empty-list<TagInterface> $tagList Tag list in document order
     * @param string $html HTML content
     * @return array<int, non-empty-list<TagInterface>> Groups keyed by the start offset of their first tag
     */
    private function groupTags(array $tagList, string $html): array
    {
        $groupStart = $tagList[0]->getStart();
        $groupList = [$groupStart => [$tagList[0]]];

        for ($i = 1, $iMax = count($tagList); $i < $iMax; $i++) {
            $currentTag = $tagList[$i];
            if ($this->isNewGroupNeeded($tagList[$i - 1], $currentTag, $html)) {
                $groupStart = $currentTag->getStart();
                $groupList[$groupStart] = [$currentTag];
                continue;
            }
            $groupList[$groupStart][] = $currentTag;
        }

        return $groupList;
    }

    /**
     * Check is new group needed
     *
     * @param TagInterface $previousTag
     * @param TagInterface $currentTag
     * @param string $html
     * @return bool
     */
    private function isNewGroupNeeded(TagInterface $previousTag, TagInterface $currentTag, string $html): bool
    {
        $betweenText = $this->getStringFromHtml->execute(
            $html,
            $previousTag->getEnd() + 1,
            $currentTag->getStart()
        );

        if (preg_match('/<[^>]+?>/is', $betweenText) === 1) {
            return true;
        }

        if ($this->isRequireJsConfigOrStaticFile($previousTag) || $this->isRequireJsOrStaticFile($previousTag)) {
            return true;
        }

        if ($this->isTagMustBeIgnored($currentTag)) {
            return true;
        }

        if (!$this->isInternalFile($previousTag) || !$this->isInternalFile($currentTag)) {
            return true;
        }

        return false;
    }

    /**
     * Check is requirejs config or static file
     *
     * @param TagInterface $tag Tag
     * @return bool Is requirejs config or static file
     */
    private function isRequireJsConfigOrStaticFile(TagInterface $tag): bool
    {
        $src = $this->getSrc($tag);

        return $src !== null
            && preg_match('/(requirejs-config(\.min)?\.js|mage\/requirejs\/static(\.min)?\.js)$/', $src) === 1;
    }

    /**
     * Check is requirejs or static file
     *
     * @param TagInterface $tag Tag
     * @return bool Is requirejs or static file
     */
    private function isRequireJsOrStaticFile(TagInterface $tag): bool
    {
        $src = $this->getSrc($tag);

        return $src !== null && preg_match('/(requirejs\/require(\.min)?\.js)$/', $src) === 1;
    }

    /**
     * Check is tag must be ignored
     *
     * @param TagInterface $tag Tag
     * @return bool Is tag must be ignored
     */
    private function isTagMustBeIgnored(TagInterface $tag): bool
    {
        return $this->isTagMustBeIgnored->execute(
            $tag->getContent(),
            [self::IGNORE_MERGE_FLAG],
            $this->config->getExcludeAnchors()
        );
    }

    /**
     * Check is internal file
     *
     * @param TagInterface $tag Tag
     * @return bool Is internal file
     */
    private function isInternalFile(TagInterface $tag): bool
    {
        $src = $this->getSrc($tag);

        return $src !== null && $this->isInternalUrl->execute($src)
            && file_exists($this->getLocalPathFromUrl->execute($src));
    }

    /**
     * The tag's "src" attribute
     *
     * @param TagInterface $tag Tag
     * @return string|null The source URL, or null when the tag has none
     */
    private function getSrc(TagInterface $tag): ?string
    {
        $src = $tag->getAttributes()['src'] ?? null;

        return is_string($src) ? $src : null;
    }

    /**
     * Insert RequireJS and static files into HTML content
     *
     * @param string $html HTML content to modify
     * @return void
     * @throws NoSuchEntityException
     */
    private function insertRequireJsFiles(string &$html): void
    {
        $requireJsKey = $this->findRequireJsKey($html);
        if ($requireJsKey === null) {
            return;
        }

        $jsTagList = $this->jsFinder->findExternal($html);

        if (!$this->requireJsManager->isDataExists($requireJsKey)) {
            $this->processRequireJsStaticFiles($jsTagList, $html, $requireJsKey);
            if ($this->response instanceof HttpResponse) {
                $this->response->setNoCacheHeaders();
            }
            return;
        }

        $this->processRequireJsMergedFiles($jsTagList, $html, $requireJsKey);
        $this->processRequireJsStaticFiles($jsTagList, $html, $requireJsKey);
    }

    /**
     * Find RequireJS key in HTML content
     *
     * @param string $html HTML content
     * @return string|null RequireJS key if found, null otherwise
     */
    private function findRequireJsKey(string $html): ?string
    {
        foreach ($this->jsFinder->findInline($html) as $tag) {
            $key = $tag->getAttributes()[RequireJsManager::SCRIPT_TAG_DATA_KEY] ?? null;
            if (is_string($key)) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Process RequireJS static files and insert them into HTML
     *
     * @param array<TagInterface> $jsTagList List of JavaScript tags
     * @param string $html HTML content to modify
     * @param string $requireJsKey RequireJS key
     * @return void
     * @throws NoSuchEntityException
     */
    private function processRequireJsStaticFiles(array $jsTagList, string &$html, string $requireJsKey): void
    {
        $this->processRequireJsScriptsByType($jsTagList, $html, $requireJsKey, true);
    }

    /**
     * Process RequireJS merged files and insert them into HTML
     *
     * @param array<TagInterface> $jsTagList List of JavaScript tags
     * @param string $html HTML content to modify
     * @param string $requireJsKey RequireJS key
     * @return void
     * @throws NoSuchEntityException
     */
    private function processRequireJsMergedFiles(array $jsTagList, string &$html, string $requireJsKey): void
    {
        $this->processRequireJsScriptsByType($jsTagList, $html, $requireJsKey, false);
    }

    /**
     * Process RequireJS scripts by type and insert them into HTML content
     *
     * @param array<TagInterface> $jsTagList List of JavaScript tags
     * @param string $html HTML content to modify
     * @param string $requireJsKey RequireJS key
     * @param bool $isStaticOnly Whether to process only static RequireJS files
     * @return void
     * @throws NoSuchEntityException
     */
    private function processRequireJsScriptsByType(
        array $jsTagList,
        string &$html,
        string $requireJsKey,
        bool $isStaticOnly
    ): void {
        $requireJsTag = $this->findRequireJsTag($jsTagList);
        if ($requireJsTag === null) {
            return;
        }

        $this->ensureRequireJsFileExists($requireJsKey);

        $scriptTag = $this->buildRequireJsScriptTag($requireJsTag, $requireJsKey, $isStaticOnly);

        $this->replaceTagInHtml($html, $requireJsTag, $scriptTag);
    }

    /**
     * Find RequireJS tag in the list of JavaScript tags
     *
     * @param array<TagInterface> $jsTagList List of JavaScript tags
     * @return TagInterface|null RequireJS tag if found, null otherwise
     */
    private function findRequireJsTag(array $jsTagList): ?TagInterface
    {
        foreach ($jsTagList as $tag) {
            if ($this->isRequireJsOrStaticFile($tag)) {
                return $tag;
            }
        }

        return null;
    }

    /**
     * Ensure the bundle file of the stored list exists, writing it whole when it does not
     *
     * @param string $requireJsKey RequireJS key
     * @return void
     * @throws NoSuchEntityException
     */
    private function ensureRequireJsFileExists(string $requireJsKey): void
    {
        $this->bundleFileWriter->writeIfAbsent(
            $this->getRequireJsResultFilePath($requireJsKey),
            fn (): string => $this->requireJsManager->getRequireJsContent($requireJsKey)
        );
    }

    /**
     * Build RequireJS script tag content
     *
     * @param TagInterface $requireJsTag Original RequireJS tag
     * @param string $requireJsKey RequireJS key
     * @param bool $isStaticOnly Whether to use static URL only
     * @return string Generated script tag content
     * @throws NoSuchEntityException
     */
    private function buildRequireJsScriptTag(TagInterface $requireJsTag, string $requireJsKey, bool $isStaticOnly): string
    {
        if ($isStaticOnly) {
            $scriptUrl = $this->getRequireJsBuildScriptUrl->execute((string)$this->getSrc($requireJsTag));
        } else {
            $scriptUrl = $this->appendCacheTimestamp($this->getRequireJsResultUrl($requireJsKey));
        }

        return $requireJsTag->getContent() . "\n" . $this->generateScriptTag($scriptUrl);
    }

    /**
     * Append cache timestamp to URL if available
     *
     * @param string $url URL to append timestamp to
     * @return string URL with timestamp appended
     */
    private function appendCacheTimestamp(string $url): string
    {
        $latestTime = $this->jsListCache->getCache()->load('last_update');
        if ((is_int($latestTime) || is_string($latestTime)) && (bool)$latestTime) {
            $url .= '?time=' . $latestTime;
        }

        return $url;
    }

    /**
     * Generate script tag HTML
     *
     * @param string $scriptUrl Script URL
     * @return string Generated script tag HTML
     */
    private function generateScriptTag(string $scriptUrl): string
    {
        return "<script type='text/javascript' src='$scriptUrl'></script>";
    }

    /**
     * Replace tag in HTML content
     *
     * @param string $html HTML content to modify
     * @param TagInterface $originalTag Original tag to replace
     * @param string $newContent New content to insert
     * @return void
     */
    private function replaceTagInHtml(string &$html, TagInterface $originalTag, string $newContent): void
    {
        $html = $this->replaceIntoHtml->execute(
            $html,
            $newContent,
            $originalTag->getStart(),
            $originalTag->getEnd()
        );
    }

    /**
     * @inheritDoc
     */
    public function getRequireJsResultFilePath(string $key): string
    {
        $fileDir = $this->cache->getRootCachePath() . DIRECTORY_SEPARATOR
            . RequireJsManager::REQUIREJS_STORAGE_DIR . DIRECTORY_SEPARATOR;

        return $fileDir . $this->getRequireJsResultFileName($key);
    }

    /**
     * Public URL of the bundle file for the URL list stored under the route key
     *
     * @param string $key Route key
     * @return string
     * @throws NoSuchEntityException
     */
    public function getRequireJsResultUrl(string $key): string
    {
        return $this->getRootCacheUrl($this->request->isSecure())
            . DIRECTORY_SEPARATOR . RequireJsManager::REQUIREJS_STORAGE_DIR
            . DIRECTORY_SEPARATOR . $this->getRequireJsResultFileName($key);
    }

    /**
     * Public URL of the page speed cache root
     *
     * @param bool $isSecure Whether the secure URL is wanted
     * @return string
     * @throws NoSuchEntityException
     */
    private function getRootCacheUrl(bool $isSecure): string
    {
        if (!$this->cache instanceof PageSpeedCache) {
            throw new \LogicException('The page speed cache in use does not publish a root URL.');
        }

        return $this->cache->getRootCacheUrl($isSecure);
    }

    /**
     * File name of the bundle for the URL list stored under the route key
     *
     * @param string $key Route key
     * @return string
     * @throws NoSuchEntityException
     */
    private function getRequireJsResultFileName(string $key): string
    {
        $urlList = [];
        foreach ($this->requireJsManager->loadUrlList($key) as $url) {
            if (is_string($url)) {
                $urlList[] = $url;
            }
        }
        sort($urlList);
        $name = md5(implode(',', $urlList));

        return md5($key . '||' . $name . '||' . $this->getLastFileChangeTimestampForUrlList->execute($urlList))
            . '.js';
    }
}
