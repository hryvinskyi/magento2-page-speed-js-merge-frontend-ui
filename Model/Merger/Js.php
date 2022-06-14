<?php
/**
 * Copyright (c) 2022. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\Merger;

use Hryvinskyi\PageSpeed\Model\AbstractMerger;
use Hryvinskyi\PageSpeedApi\Api\CreateFileByContentInterface;
use Hryvinskyi\PageSpeedApi\Api\Finder\Result\RawInterface;
use Hryvinskyi\PageSpeedApi\Api\GetContentFromTagInterface;
use Hryvinskyi\PageSpeedApi\Api\GetLocalPathFromUrlInterface;
use Hryvinskyi\PageSpeedApi\Model\CacheInterface;
use Hryvinskyi\PageSpeedJsMerge\Api\ConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Psr\Log\LoggerInterface;

class Js extends AbstractMerger
{
    private RequestInterface $request;
    private CacheInterface $cache;
    private ConfigInterface $config;
    private GetContentFromTagInterface $getContentFromTag;
    private CreateFileByContentInterface $createFileByContent;
    private EventManager $eventManager;

    /**
     * @param GetLocalPathFromUrlInterface $getLocalPathFromUrl
     * @param RequestInterface $request
     * @param CacheInterface $cache
     * @param ConfigInterface $config
     * @param GetContentFromTagInterface $getContentFromTag
     * @param CreateFileByContentInterface $createFileByContent
     * @param EventManager $eventManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        GetLocalPathFromUrlInterface $getLocalPathFromUrl,
        RequestInterface $request,
        CacheInterface $cache,
        ConfigInterface $config,
        GetContentFromTagInterface $getContentFromTag,
        CreateFileByContentInterface $createFileByContent,
        EventManager $eventManager,
        LoggerInterface $logger
    ) {
        parent::__construct($getLocalPathFromUrl, $logger);
        $this->request = $request;
        $this->cache = $cache;
        $this->config = $config;
        $this->getContentFromTag = $getContentFromTag;
        $this->createFileByContent = $createFileByContent;
        $this->eventManager = $eventManager;
    }

    /**
     * @inheridoc
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function mergeFileList(array $files): ?string
    {
        $isSecure = $this->request->isSecure();
        $targetFilename = md5($this->getMergeFilename($files)) . '.js';
        $targetDir = $this->cache->getRootCachePath() . DIRECTORY_SEPARATOR . 'js';
        $resultUrl = $this->cache->getRootCacheUrl($isSecure) . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . $targetFilename;

        if (file_exists($targetDir . DIRECTORY_SEPARATOR . $targetFilename)) {
            return $resultUrl;
        }

        if (!@mkdir($targetDir, $this->config->getFolderPermission(), true) && !is_dir($targetDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $targetDir));
        }

        if (!is_writable($targetDir)) {
            return null;
        }

        $mergeFilesResult = $this->mergeFiles(
            $files,
            $targetDir . DIRECTORY_SEPARATOR . $targetFilename,
            false,
            function ($file, $contents) {
                $data = new DataObject();
                $data->setData('content', $contents);
                $data->setData('file', $file);
                $this->eventManager->dispatch('pagespeed_prepare_content_on_merge_files', ['data' => $data]);

                return $data->getData('content') . ';';
            },
            ['js']
        );

        if ($mergeFilesResult) {
            @chmod($targetDir . DIRECTORY_SEPARATOR . $targetFilename, $this->config->getFilePermission());
            return $resultUrl;
        }

        return null;
    }

    /**
     * @inheridoc
     */
    public function getPathFromTag(RawInterface $tag): string
    {
        $attributes = $tag->getAttributes();
        if (array_key_exists('src', $attributes)) {
            return $attributes['src'];
        }

        return $this->createFileByContent->execute(
            $this->getContentFromTag->execute($tag->getContent()),
            $this->cache->getRootCachePath() . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'inline',
            'js'
        );
    }

    /**
     * @param array $fileList
     *
     * @return string
     */
    private function getMergeFilename(array $fileList): string
    {
        $result = [];
        foreach ($fileList as $filename) {
            if (strpos($filename, 'requirejs-config.js') !== false ||
                strpos($filename, 'requirejs-config.min.js') !== false ||
                strpos($filename, $this->cache->getRootCachePath()) === 0
            ) {
                $result[] = $filename;
            } else {
                $timestamp = filemtime(realpath($filename));
                $result[] = $filename . '+' . $timestamp;
            }
        }
        return implode(',', $result);
    }
}
