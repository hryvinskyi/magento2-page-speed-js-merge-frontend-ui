<?php
/**
 * Copyright (c) 2022-2023. MageCloud.  All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Observer;

use Hryvinskyi\PageSpeedApi\Model\CacheInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MatthiasMullie\Minify;

class MinifyMergeFiles implements ObserverInterface
{
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }


    /**
     * @inheritDoc
     */
    public function execute(Observer $observer)
    {
        $data = $observer->getEvent()->getData('data');
        $content = $data->getContent();
        $file = $data->getFile();

        if ($this->isMinifiableHtml($file)) {
            $content = $this->minifyHtmlContent($content);
        }

        if ($this->isMinifiableJs($file)) {
            $content = $this->minifyJsContent($content);
        }

        $data->setContent($content);
    }

    /**
     * Check if js file is minifiable
     *
     * @param string $file
     * @return bool
     */
    private function isMinifiableJs(string $file): bool
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $isExtensionJs = $extension === 'js';
        $isFileIsNotMinified = substr($file, -7) !== '.min.js';
        $isFileIsAlreadyProcessed = strpos($file, $this->cache->getRootCachePath()) === 0;

        return $isExtensionJs && $isFileIsNotMinified && !$isFileIsAlreadyProcessed;
    }

    /**
     * Minify JS content
     *
     * @param string $content
     * @return string
     */
    private function minifyJsContent(string $content): string
    {
        try {
            $minifier = new Minify\JS();
            $minifier->add($content);
            $content = $minifier->minify();
        } catch (\Throwable $exception) {
            // If minification fails, return the original content
        }

        return $content;
    }

    /**
     * Check if Html file is minifiable
     *
     * @param string $file
     * @return bool
     */
    private function isMinifiableHtml(string $file): bool
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);

        return $extension === 'html';
    }

    /**
     * Minify HTML content
     *
     * @param string $content
     * @return string
     */
    private function minifyHtmlContent(string $content): string
    {
        return preg_replace('!\s+!', ' ', $content);
    }
}
