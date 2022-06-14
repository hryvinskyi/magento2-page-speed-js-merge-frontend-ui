<?php
/**
 * Copyright (c) 2022. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Controller\Js;

use Hryvinskyi\PageSpeedJsMerge\Api\MergeJsInterface as JsProcessor;
use Hryvinskyi\PageSpeedJsMerge\Model\Cache\PageCachePurger;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

class Collect implements ActionInterface, HttpPostActionInterface
{
    private JsonFactory $jsonFactory;
    private RequireJsManager $requireJsManager;
    private JsProcessor $jsProcessor;
    private PageCachePurger $pageCachePurger;
    private RequestInterface $request;

    /**
     * @param JsonFactory $jsonFactory
     * @param RequireJsManager $requireJsManager
     * @param JsProcessor $jsProcessor
     * @param PageCachePurger $pageCachePurger
     * @param RequestInterface $request
     */
    public function __construct(
        JsonFactory $jsonFactory,
        RequireJsManager $requireJsManager,
        JsProcessor $jsProcessor,
        PageCachePurger $pageCachePurger,
        RequestInterface $request
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->requireJsManager = $requireJsManager;
        $this->jsProcessor = $jsProcessor;
        $this->pageCachePurger = $pageCachePurger;
        $this->request = $request;
    }

    /**
     * @return Json
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Zend_Cache_Exception
     */
    public function execute(): Json
    {
        $key = $this->request->getParam('key');
        $tags = $this->request->getParam('tags');
        $list = $this->request->getParam('list');
        $baseUrl = $this->request->getParam('base');

        if ($key === null || $list === null || $baseUrl === null || $tags === null) {
            return $this->jsonFactory->create()->setData(['result' => false]);
        }

        $currentList = $this->requireJsManager->loadUrlList($key);

        if (count($currentList) === 0) {
            array_unshift($list, $baseUrl);
        } else {
            $list = array_merge($currentList, $list);
        }

        $result = $this->requireJsManager->saveUrlList($list, $key);
        $filePath = $this->jsProcessor->getRequireJsResultFilePath($key);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
        $this->pageCachePurger->purgeByTags(explode(',', $tags));

        return $this->jsonFactory->create()->setData(['result' => $result]);
    }
}
