<?php
/**
 * Copyright (c) 2022. All rights reserved.
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
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\Merger\Js as JsMerger;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedApi\Api\Finder\Result\RawInterface;

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
        JsMerger $jsMerger
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

            $firstTag = reset($group);
            $lastTag = end($group);
            $replaceData[] = [
                'start' => $firstTag->getStart(),
                'end' => $lastTag->getEnd(),
                'url' => $mergedUrl
            ];
        }

        foreach (array_reverse($replaceData) as $replaceElData) {
            $replacement = '<script type="text/javascript" src="' . $replaceElData['url'] . '"></script>';
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
            if ($this->isTagMustBeIgnored->execute(
                $tag->getContent(),
                [self::IGNORE_MERGE_FLAG],
                $this->config->getExcludeAnchors()
            )) {
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
     * Insert requirejs and static files into html
     *
     * @param string $html HTML content
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function insertRequireJsFiles(string &$html): void
    {
        $key = $this->findRequireJsKey($html);
        if ($key === null) {
            return;
        }

        if (!$this->requireJsManager->isDataExists($key)) {
            $this->response->setNoCacheHeaders();
            return;
        }

        $this->insertRequireJsScripts($html, $key);
    }

    /**
     * Find requirejs key in html
     *
     * @param string $html HTML content
     * @return string|null RequireJs key
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
     * Insert requirejs and static files into html
     *
     * @param string $html HTML content
     * @param string $key RequireJs key
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function insertRequireJsScripts(string &$html, string $key): void
    {
        $jsTagList = $this->jsFinder->findExternal($html);
        foreach ($jsTagList as $tag) {
            if (!$this->isRequireJsOrStaticFile($tag)) {
                continue;
            }

            $filePath = $this->getRequireJsResultFilePath($key);
            if (!file_exists($filePath)) {
                $this->putContentInFile->execute(
                    $this->requireJsManager->getRequireJsContent($key),
                    $filePath
                );
            }

            $urlToFile = $this->getRequireJsResultUrl($key);
            $urlToLib = $this->getRequireJsBuildScriptUrl->execute($tag->getAttributes()['src']);
            $insertString = $tag->getContent() . "\n"
                . "<script type='text/javascript' src='$urlToFile'></script>"
                . "<script type='text/javascript' src='$urlToLib'></script>";

            $html = $this->replaceIntoHtml->execute($html, $insertString, $tag->getStart(), $tag->getEnd());
            break;
        }
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
