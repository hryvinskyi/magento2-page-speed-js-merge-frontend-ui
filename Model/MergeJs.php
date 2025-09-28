<?php
/**
 * Copyright (c) 2025. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

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
use Hryvinskyi\PageSpeedApi\Api\PutContentInFileInterface;
use Hryvinskyi\PageSpeedApi\Model\CacheInterface;
use Hryvinskyi\PageSpeedJsMerge\Api\ConfigInterface;
use Hryvinskyi\PageSpeedJsMerge\Api\MergeJsInterface;
use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\Merger\Js as JsMerger;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\MergeProcessorPoolInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Magento\Framework\Exception\NoSuchEntityException;

class MergeJs implements MergeJsInterface
{
    private const IGNORE_MERGE_FLAG = 'ignore_merge';

    private ConfigInterface $config;
    private CacheInterface $cache;
    private ResponseInterface $response;
    private RequestInterface $request;
    private RequireJsManager $requireJsManager;
    private JsFinderInterface $jsFinder;
    private ReplaceIntoHtmlInterface $replaceIntoHtml;
    private PutContentInFileInterface $putContentInFile;
    private IsTagMustBeIgnoredInterface $isTagMustBeIgnored;
    private IsInternalUrlInterface $isInternalUrl;
    private GetStringLengthFromUrlInterface $getStringLengthFromUrl;
    private GetFileContentByUrlInterface $getFileContentByUrl;
    private GetLastFileChangeTimestampForUrlList $getLastFileChangeTimestampForUrlList;
    private GetRequireJsBuildScriptUrlInterface $getRequireJsBuildScriptUrl;
    private GetStringFromHtmlInterface $getStringFromHtml;
    private GetLocalPathFromUrlInterface $getLocalPathFromUrl;
    private JsMerger $jsMerger;
    private JsList $jsListCache;
    private MergeProcessorPoolInterface $mergeProcessorPool;

    /**
     * @param ConfigInterface $config
     * @param CacheInterface $cache
     * @param ResponseInterface $response
     * @param RequestInterface $request
     * @param RequireJsManager $requireJsManager
     * @param JsFinderInterface $jsFinder
     * @param ReplaceIntoHtmlInterface $replaceIntoHtml
     * @param PutContentInFileInterface $putContentInFile
     * @param IsTagMustBeIgnoredInterface $isTagMustBeIgnored
     * @param IsInternalUrlInterface $isInternalUrl
     * @param GetStringLengthFromUrlInterface $getStringLengthFromUrl
     * @param GetFileContentByUrlInterface $getFileContentByUrl
     * @param GetLastFileChangeTimestampForUrlList $getLastFileChangeTimestampForUrlList
     * @param GetRequireJsBuildScriptUrlInterface $getRequireJsBuildScriptUrl
     * @param GetStringFromHtmlInterface $getStringFromHtml
     * @param GetLocalPathFromUrlInterface $getLocalPathFromUrl
     * @param JsMerger $jsMerger
     * @param MergeProcessorPoolInterface $mergeProcessorPool
     */
    public function __construct(
        ConfigInterface $config,
        CacheInterface $cache,
        ResponseInterface $response,
        RequestInterface $request,
        RequireJsManager $requireJsManager,
        JsFinderInterface $jsFinder,
        ReplaceIntoHtmlInterface $replaceIntoHtml,
        PutContentInFileInterface $putContentInFile,
        IsTagMustBeIgnoredInterface $isTagMustBeIgnored,
        IsInternalUrlInterface $isInternalUrl,
        GetStringLengthFromUrlInterface $getStringLengthFromUrl,
        GetFileContentByUrlInterface $getFileContentByUrl,
        GetLastFileChangeTimestampForUrlList $getLastFileChangeTimestampForUrlList,
        GetRequireJsBuildScriptUrlInterface $getRequireJsBuildScriptUrl,
        GetStringFromHtmlInterface $getStringFromHtml,
        GetLocalPathFromUrlInterface $getLocalPathFromUrl,
        JsMerger $jsMerger,
        JsList $jsListCache,
        MergeProcessorPoolInterface $mergeProcessorPool
    ) {
        $this->config = $config;
        $this->cache = $cache;
        $this->response = $response;
        $this->request = $request;
        $this->requireJsManager = $requireJsManager;
        $this->jsFinder = $jsFinder;
        $this->replaceIntoHtml = $replaceIntoHtml;
        $this->putContentInFile = $putContentInFile;
        $this->isTagMustBeIgnored = $isTagMustBeIgnored;
        $this->isInternalUrl = $isInternalUrl;
        $this->getStringLengthFromUrl = $getStringLengthFromUrl;
        $this->getFileContentByUrl = $getFileContentByUrl;
        $this->getLastFileChangeTimestampForUrlList = $getLastFileChangeTimestampForUrlList;
        $this->getRequireJsBuildScriptUrl = $getRequireJsBuildScriptUrl;
        $this->getStringFromHtml = $getStringFromHtml;
        $this->getLocalPathFromUrl = $getLocalPathFromUrl;
        $this->jsMerger = $jsMerger;
        $this->jsListCache = $jsListCache;
        $this->mergeProcessorPool = $mergeProcessorPool;
    }

    /**
     * @inheridoc
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
            $attributes = $tag->getAttributes();
            if (!$this->isInternalUrl->execute($attributes['src'])) {
                continue;
            }

            $contentLength = $this->getStringLengthFromUrl->execute($attributes['src']);
            if ($contentLength > $this->config->getInlineMaxLength()) {
                continue;
            }

            $content = $this->getFileContentByUrl->execute($attributes['src']);
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
     * @inheridoc
     */
    public function merge(string &$html): void
    {
        $this->insertRequireJsFiles($html);
        $tagList = $this->config->isMergeInlineJsEnabled()
            ? $this->jsFinder->findAll($html)
            : $this->jsFinder->findExternal($html);

        if (empty($tagList)) {
            return;
        }

        $groupList = $this->groupTags($tagList, $html);

        $replaceData = [];
        foreach ($groupList as $group) {
            if (count($group) < 2) {
                continue;
            }

            $mergedUrl = $this->jsMerger->merge($group);
            if ($mergedUrl === null) {
                continue;
            }

            // Process attributes using merge processor pool extension point
            $attributes = $this->mergeProcessorPool->processAttributes([], $mergedUrl);

            if ($latestTime = $this->jsListCache->getCache()->load('last_update')) {
                $mergedUrl .= '?time=' . $latestTime;
            }

            $firstTag = reset($group);
            $lastTag = end($group);
            $replaceData[] = [
                'start' => $firstTag->getStart(),
                'end' => $lastTag->getEnd(),
                'url' => $mergedUrl,
                'attributes' => $attributes
            ];
        }

        foreach (array_reverse($replaceData) as $replaceElData) {
            // Build attributes string from processed attributes
            $attributesString = '';
            foreach ($replaceElData['attributes'] as $name => $value) {
                $attributesString .= ' ' . $name . '="' . htmlspecialchars($value) . '"';
            }

            $replacement = '<script type="text/javascript" src="' . $replaceElData['url'] . '"' . $attributesString . '></script>';
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
     * @param array $tagList
     * @return void
     */
    private function excludeIgnoredTags(array &$tagList): void
    {
        foreach ($tagList as $key => $tag) {
            if ($this->isTagMustBeIgnored->execute($tag->getContent(), [self::IGNORE_MERGE_FLAG], $this->config->getExcludeAnchors())) {
                unset($tagList[$key]);
            }
        }
    }

    /**
     * Group tags
     *
     * @param array $tagList Tag list
     * @param string $html HTML content
     * @return array[]
     */
    private function groupTags(array $tagList, string $html): array
    {
        $firstTag = reset($tagList);
        $groupList = [$firstTag->getStart() => [$firstTag]];

        for ($i = 1, $iMax = count($tagList); $i < $iMax; $i++) {
            $previousTag = $tagList[$i - 1];
            $currentTag = $tagList[$i];
            $isNeedNewGroup = $this->isNewGroupNeeded($previousTag, $currentTag, $html);

            if ($isNeedNewGroup) {
                $groupList[$currentTag->getStart()] = array($currentTag);
                next($groupList);
                continue;
            }
            $groupList[key($groupList)][] = $currentTag;
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

        if ($this->isTagMustBeIgnored($currentTag) || $this->isTagMustBeIgnored($currentTag)) {
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
        $attributes = $tag->getAttributes();
        return isset($attributes['src']) && preg_match('/(requirejs-config(\.min)?\.js|mage\/requirejs\/static(\.min)?\.js)$/', $attributes['src']) === 1;
    }

    /**
     * Check is requirejs or static file
     *
     * @param TagInterface $tag Tag
     * @return bool Is requirejs or static file
     */
    private function isRequireJsOrStaticFile(TagInterface $tag): bool
    {
        $attributes = $tag->getAttributes();
        return isset($attributes['src']) && preg_match('/(requirejs\/require(\.min)?\.js)$/', $attributes['src']) === 1;
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
        $attributes = $tag->getAttributes();
        return isset($attributes['src']) && $this->isInternalUrl->execute($attributes['src'])
            && file_exists($this->getLocalPathFromUrl->execute($attributes['src']));
    }

    /**
     * Insert RequireJS and static files into HTML content
     *
     * @param string $html HTML content to modify
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
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
            $this->response->setNoCacheHeaders();
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
        $tagList = $this->jsFinder->findInline($html);
        foreach ($tagList as $tag) {
            $attributes = $tag->getAttributes();
            if (isset($attributes[RequireJsManager::SCRIPT_TAG_DATA_KEY])) {
                return $attributes[RequireJsManager::SCRIPT_TAG_DATA_KEY];
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
    private function processRequireJsScriptsByType(array $jsTagList, string &$html, string $requireJsKey, bool $isStaticOnly): void
    {
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
     * Ensure RequireJS file exists and create it if necessary
     *
     * @param string $requireJsKey RequireJS key
     * @return void
     * @throws NoSuchEntityException
     */
    private function ensureRequireJsFileExists(string $requireJsKey): void
    {
        $filePath = $this->getRequireJsResultFilePath($requireJsKey);
        if (!file_exists($filePath)) {
            $this->putContentInFile->execute(
                $this->requireJsManager->getRequireJsContent($requireJsKey),
                $filePath
            );
        }
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
        $tagAttributes = $requireJsTag->getAttributes();
        $originalContent = $requireJsTag->getContent();

        if ($isStaticOnly) {
            $scriptUrl = $this->getRequireJsBuildScriptUrl->execute($tagAttributes['src']);
        } else {
            $scriptUrl = $this->getRequireJsResultUrl($requireJsKey);
            $scriptUrl = $this->appendCacheTimestamp($scriptUrl);
        }

        return $originalContent . "\n" . $this->generateScriptTag($scriptUrl);
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
        if ($latestTime) {
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
     * @param string $key
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getRequireJsResultFilePath(string $key): string
    {
        $fileDir = $this->cache->getRootCachePath() . DIRECTORY_SEPARATOR . RequireJsManager::REQUIREJS_STORAGE_DIR . DIRECTORY_SEPARATOR;
        return $fileDir . $this->getRequireJsResultFileName($key);
    }

    /**
     * @param string $key
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getRequireJsResultUrl(string $key): string
    {
        return $this->cache->getRootCacheUrl($this->request->isSecure())
            . DIRECTORY_SEPARATOR . RequireJsManager::REQUIREJS_STORAGE_DIR . DIRECTORY_SEPARATOR . $this->getRequireJsResultFileName($key);
    }

    /**
     * @param string $key
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getRequireJsResultFileName(string $key): string
    {
        $urlList = $this->requireJsManager->loadUrlList($key) ?? [];
        sort($urlList);
        $name = md5(implode(',', $urlList));
        return md5($key . '||' . $name . '||' . $this->getLastFileChangeTimestampForUrlList->execute($urlList)) . '.js';
    }
}
