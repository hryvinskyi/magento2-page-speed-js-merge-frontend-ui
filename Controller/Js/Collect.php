<?php
/**
 * Copyright (c) 2025. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Controller\Js;

use Hryvinskyi\PageSpeedApi\Api\GetLocalPathFromUrlInterface;
use Hryvinskyi\PageSpeedApi\Api\PutContentInFileInterface;
use Hryvinskyi\PageSpeedJsMerge\Api\MergeJsInterface as JsFileProcessor;
use Hryvinskyi\PageSpeedJsMerge\Model\Cache\JsList;
use Hryvinskyi\PageSpeedJsMerge\Model\Cache\PageCachePurger;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\NoSuchEntityException;

class Collect implements ActionInterface, HttpPostActionInterface
{
    private JsonFactory $jsonFactory;
    private RequireJsManager $requireJsManager;
    private JsFileProcessor $jsFileProcessor;
    private PageCachePurger $cachePurger;
    private RequestInterface $request;
    private ?JsList $cache;
    private ?PutContentInFileInterface $putContentInFile;
    private ?GetLocalPathFromUrlInterface $getLocalPathFromUrl;

    /**
     * @param JsonFactory $jsonFactory
     * @param RequireJsManager $requireJsManager
     * @param JsFileProcessor $jsFileProcessor
     * @param PageCachePurger $cachePurger
     * @param RequestInterface $request
     * @param JsList|null $cache
     * @param PutContentInFileInterface|null $putContentInFile
     * @param GetLocalPathFromUrlInterface|null $getLocalPathFromUrl
     */
    public function __construct(
        JsonFactory $jsonFactory,
        RequireJsManager $requireJsManager,
        JsFileProcessor $jsFileProcessor,
        PageCachePurger $cachePurger,
        RequestInterface $request,
        JsList $cache = null,
        PutContentInFileInterface $putContentInFile = null,
        GetLocalPathFromUrlInterface $getLocalPathFromUrl = null
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->requireJsManager = $requireJsManager;
        $this->jsFileProcessor = $jsFileProcessor;
        $this->cachePurger = $cachePurger;
        $this->request = $request;
        $this->cache = $cache ?? ObjectManager::getInstance()->get(JsList::class);
        $this->putContentInFile = $putContentInFile ?? ObjectManager::getInstance()->get(PutContentInFileInterface::class);
        $this->getLocalPathFromUrl = $getLocalPathFromUrl ?? ObjectManager::getInstance()->get(GetLocalPathFromUrlInterface::class);
    }

    /**
     * Executes the controller action.
     *
     * Expected request parameters:
     *  - key:   Unique cache key.
     *  - tags:  Comma-separated cache tags.
     *  - list:  List of new static content URLs.
     *  - base:  Base URL to prepend if no cached URLs exist.
     *
     * @return Json
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Zend_Cache_Exception
     */
    public function execute(): Json
    {
        $params = $this->getValidatedParams();
        if ($params === null) {
            return $this->jsonFactory->create()->setData(['result' => false]);
        }

        [$mergedUrlList, $hasNewItems] = $this->mergeUrlLists(
            $params['cacheKey'],
            $params['baseUrl'],
            $params['newUrlList']
        );

        if (!$hasNewItems) {
            return $this->jsonFactory->create()->setData(['result' => true]);
        }

        $saveResult = $this->requireJsManager->saveUrlList($mergedUrlList, $params['cacheKey']);
        $this->deleteRequireJsResultFile($params['cacheKey']);
        $this->purgeCache($params['cacheTags']);

        return $this->jsonFactory->create()->setData(['result' => $saveResult]);
    }

    /**
     * Validates and extracts the required parameters from the request.
     *
     * @return array{
     *     cacheKey: string,
     *     cacheTags: string,
     *     newUrlList: array<string>,
     *     baseUrl: string
     * }|null
     */
    private function getValidatedParams(): ?array
    {
        $cacheKey = $this->request->getParam('key');
        $cacheTags = $this->request->getParam('tags');
        $newUrlList = $this->request->getParam('list');
        $baseUrl = $this->request->getParam('base');

        if ($cacheKey === null || $cacheTags === null || $newUrlList === null || $baseUrl === null) {
            return null;
        }

        if (!is_array($newUrlList)) {
            $newUrlList = (array)$newUrlList;
        }

        return [
            'cacheKey'  => $cacheKey,
            'cacheTags' => $cacheTags,
            'newUrlList'=> $newUrlList,
            'baseUrl'   => $baseUrl,
        ];
    }

    /**
     * Merges the new URL list with the cached URL list.
     *
     * @param string $cacheKey The cache key.
     * @param string $baseUrl The base URL.
     * @param array $newUrlList The new URL list.
     *
     * @return array An array containing the merged URL list and a flag indicating whether new items were added.
     * @throws NoSuchEntityException
     */
    private function mergeUrlLists(string $cacheKey, string $baseUrl, array $newUrlList): array
    {
        $existingUrlList = $this->requireJsManager->loadUrlList($cacheKey);
        $hasNewItems = false;

        // Case 1: No existing cached URLs.
        if (empty($existingUrlList)) {
            if (!in_array($baseUrl, $newUrlList, true)) {
                array_unshift($newUrlList, $baseUrl);
            }
            return [$newUrlList, true];
        }

        $mergedUrlList = $existingUrlList;
        $existingUrlSet = array_flip($existingUrlList);

        // Add new URLs that exist as local files.
        foreach ($newUrlList as $url) {
            if (!$this->isUrlAlreadyAddedAndExists($url, $existingUrlSet)) {
                $mergedUrlList[] = $url;
                $existingUrlSet[$url] = true;
                $hasNewItems = true;
            }
        }

        // Case 2: Check the processed file for missing URLs.
        if (!$hasNewItems) {
            $jsResultFilePath = $this->jsFileProcessor->getRequireJsResultFilePath($cacheKey);
            if (file_exists($jsResultFilePath)) {
                $fileContent = (string) file_get_contents($jsResultFilePath);
                foreach ($newUrlList as $url) {
                    if (!$this->isLocalFileAvailable($url)) {
                        continue;
                    }
                    $relativeUrl = str_replace($baseUrl, '', $url);
                    $pattern = '"' . $relativeUrl . '":"';
                    if (!str_contains($fileContent, $pattern)) {
                        $mergedUrlList[] = $url;
                        $hasNewItems = true;
                    }
                }
            } else {
                // The processed file is missing, so we add all new URLs.
                $hasNewItems = true;
            }
        }

        if ($hasNewItems) {
            // Remove URLs that no longer exist locally.
            $mergedUrlList = array_filter($mergedUrlList, function ($url) {
                if (!$this->isLocalFileAvailable($url)) {
                    return false;
                }
                return true;
            });
        }

        return [array_values($mergedUrlList), $hasNewItems];
    }

    /**
     * Checks if a URL is already present in the set and that its corresponding local file exists.
     *
     * @param string $url
     * @param array $existingUrlSet
     * @return bool
     */
    private function isUrlAlreadyAddedAndExists(string $url, array $existingUrlSet): bool
    {
        if (isset($existingUrlSet[$url])) {
            return true;
        }
        return !$this->isLocalFileAvailable($url);
    }

    /**
     * Determines whether the local file for the given URL exists.
     *
     * @param string $url
     * @return bool
     */
    private function isLocalFileAvailable(string $url): bool
    {
        $localPath = $this->getLocalPathFromUrl->execute($url);
        return is_file($localPath);
    }

    /**
     * Deletes the processed RequireJS result file if it exists.
     *
     * @param string $cacheKey The cache key used to determine the file path.
     * @throws NoSuchEntityException|\Zend_Cache_Exception
     */
    private function deleteRequireJsResultFile(string $cacheKey): void
    {
        $jsResultFilePath = $this->jsFileProcessor->getRequireJsResultFilePath($cacheKey);
        if (file_exists($jsResultFilePath)) {
            // Using the error suppression operator (@) is not ideal.
            // Consider proper error handling or logging for production use.
            @unlink($jsResultFilePath);
        }

        if (!file_exists($jsResultFilePath)) {
            $content = $this->requireJsManager->getRequireJsContent($cacheKey);
            $this->putContentInFile->execute($content, $jsResultFilePath);
        }

        $this->cache->getCache()->save(time(), 'last_update');
    }

    /**
     * Purges cache by the specified tags.
     *
     * @param string|array $cacheTags Cache tags as a comma-separated string or an array.
     */
    private function purgeCache($cacheTags): void
    {
        $tags = is_string($cacheTags) ? explode(',', $cacheTags) : (array)$cacheTags;

        foreach (array_chunk($tags, 50) as $chunk) {
            $this->cachePurger->purgeByTags($chunk);
        }
    }
}
