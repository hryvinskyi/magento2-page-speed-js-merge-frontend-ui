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
    private array $ignoreMergeFlagList = [
        self::FLAG_IGNORE_MERGE
    ];
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
        $this->excludeIgnoreTagFromList($tagList, $this->ignoreMergeFlagList);
        if (count($tagList) === 0) {
            return;
        }

        $replaceData = [];
        foreach ($tagList as $tag) {
            /** @var TagInterface $tag */
            $attributes = $tag->getAttributes();
            if (!$this->isInternalUrl->execute($attributes['src'])) {
                continue;
            }
            $inlineMaxLength = $this->config->getInlineMaxLength();
            $contentLength = $this->getStringLengthFromUrl->execute($attributes['src']);
            if ($contentLength > $inlineMaxLength) {
                continue;
            }
            $content = $this->getFileContentByUrl->execute($attributes['src']);
            if ($content === '' || strlen($content) > $inlineMaxLength) {
                continue;
            }

            $replaceData[] = array(
                'start' => $tag->getStart(),
                'end' => $tag->getEnd(),
                'content' => '<script type="text/javascript">' . $content . '</script>'
            );
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
        if ($this->config->isMergeInlineJsEnabled()) {
            $tagList = $this->jsFinder->findAll($html);
        } else {
            $tagList = $this->jsFinder->findExternal($html);
        }

        if (count($tagList) === 0) {
            return;
        }

        /** @var TagInterface $firstTag */
        $firstTag = reset($tagList);
        $groupList = [$firstTag->getStart() => [$firstTag]];
        for ($i = 1, $iMax = count($tagList); $i < $iMax; $i++) {
            /** @var TagInterface $previousTag */
            /** @var TagInterface $currentTag */
            $previousTag = $tagList[$i - 1];
            $currentTag = $tagList[$i];
            $isNeedNewGroup = false;
            $betweenText = $this->getStringFromHtml->execute(
                $html,
                $previousTag->getEnd() + 1,
                $currentTag->getStart()
            );
            if (preg_match('/<[^>]+?>/is', $betweenText) === 1) {
                $isNeedNewGroup = true;
            }

            $previousAttributes = $previousTag->getAttributes();
            $currentAttributes = $currentTag->getAttributes();

            if (array_key_exists('src', $previousAttributes)) {
                if (preg_match('/requirejs-config.js$/', $previousAttributes['src']) === 1) {
                    $isNeedNewGroup = true;
                }
            }

            if (array_key_exists('src', $currentAttributes)) {
                if (preg_match('/' . CacheInterface::MAIN_FOLDER . '\/requirejs\/[a-f0-9]{32}\.js$/', $previousAttributes['src']) === 1) {
                    $isNeedNewGroup = true;
                }
            }

            if (array_key_exists('src', $currentAttributes)) {
                if (preg_match('/requirejs\/require.js$/', $previousAttributes['src']) === 1) {
                    $isNeedNewGroup = true;
                }
            }

            $isCurrentTagMustBeIgnored = $this->isTagMustBeIgnored->execute(
                $currentTag->getContent(),
                $this->ignoreMergeFlagList,
                $this->config->getExcludeAnchors()
            );
            $isPreviousTagMustBeIgnored = $this->isTagMustBeIgnored->execute(
                $previousTag->getContent(),
                $this->ignoreMergeFlagList,
                $this->config->getExcludeAnchors()
            );

            if ($isPreviousTagMustBeIgnored || $isCurrentTagMustBeIgnored) {
                $isNeedNewGroup = true;
            }

            if (array_key_exists('src', $previousAttributes)) {
                if (!$this->isInternalUrl->execute($previousAttributes['src'])
                    || !file_exists($this->getLocalPathFromUrl->execute($previousAttributes['src']))) {
                    $isNeedNewGroup = true;
                }
            }
            if (array_key_exists('src', $currentAttributes)) {
                if (!$this->isInternalUrl->execute($currentAttributes['src'])
                    || !file_exists($this->getLocalPathFromUrl->execute($currentAttributes['src']))) {
                    $isNeedNewGroup = true;
                }
            }
            if ($isNeedNewGroup) {
                $groupList[$currentTag->getStart()] = array($currentTag);
                next($groupList);
                continue;
            }
            $groupList[key($groupList)][] = $currentTag;
        }

        $replaceData = [];

        foreach ($groupList as $group) {
            if (count($group) < 2) {
                continue;
            }
            $mergedUrl = $this->jsMerger->merge($group);

            if ($mergedUrl === null) {
                continue;
            }

            /** @var RawInterface $firstTag */
            /** @var RawInterface $lastTag */
            $firstTag = reset($group);
            $lastTag = end($group);
            $replaceData[] = array(
                'start' => $firstTag->getStart(),
                'end' => $lastTag->getEnd(),
                'url' => $mergedUrl
            );
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
     * @param array $tagList
     * @param array $ignoreFlagList
     *
     * @return $this
     */
    private function excludeIgnoreTagFromList(array &$tagList, array $ignoreFlagList): void
    {
        foreach ($tagList as $key => $tag) {
            /** @var TagInterface $tag */
            $isTagMustBeIgnored = $this->isTagMustBeIgnored->execute(
                $tag->getContent(),
                $ignoreFlagList,
                $this->config->getExcludeAnchors()
            );

            if ($isTagMustBeIgnored) {
                unset($tagList[$key]);
            }
        }
    }

    /**
     * @param string $html
     * @return void
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function insertRequireJsFiles(string &$html): void
    {
        $requireJsList = $key = null;
        $tagList = $this->jsFinder->findInline($html);
        foreach ($tagList as $tag) {
            /** @var TagInterface $tag */
            $attributes = $tag->getAttributes();
            if (!array_key_exists(RequireJsManager::SCRIPT_TAG_DATA_KEY, $attributes)) {
                continue;
            }
            $key = $attributes[RequireJsManager::SCRIPT_TAG_DATA_KEY];
            break;
        }

        if ($key === null) {
            return;
        }

        if ($this->requireJsManager->isDataExists($key) === false) {
            $this->response->setNoCacheHeaders();
            return;
        }

        $jsTagList = $this->jsFinder->findExternal($html);
        foreach ($jsTagList as $tag) {
            /** @var TagInterface $tag */
            $attributes = $tag->getAttributes();
            $src = $attributes['src'];
            if (!preg_match('/requirejs\/require\.(min\.)?js/', $src)) {
                continue;
            }
            if (!preg_match('/requirejs\/require\.js/', $src)) {
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
            $urlToLib = $this->getRequireJsBuildScriptUrl->execute($src);
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
        $urlList = $this->requireJsManager->loadUrlList($key);
        $urlList = $urlList ?? [];
        sort($urlList);
        $name = md5(implode(',', $urlList));
        return md5($key . '||' . $name . '||' . $this->getLastFileChangeTimestampForUrlList->execute($urlList)) . '.js';
    }
}
