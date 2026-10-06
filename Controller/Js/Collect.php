<?php
/**
 * Copyright (c) 2025-2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Controller\Js;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\RecordCollectedUrlsInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * Receives the RequireJS modules a storefront page loaded that its merged bundle did not contain.
 *
 * Request parameters: "key" (the collect token printed into the page), "base" (the page's RequireJS base URL) and
 * "list" (module URLs). A "tags" parameter, which pages rendered by earlier releases still send, is ignored. Anything
 * of the wrong shape is answered {"result": false}, never with an error page.
 */
class Collect implements HttpPostActionInterface
{
    /**
     * @param JsonFactory $jsonFactory Creates the JSON answer
     * @param RequestInterface $request Current request
     * @param RecordCollectedUrlsInterface $recordCollectedUrls Records the reported modules
     */
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly RequestInterface $request,
        private readonly RecordCollectedUrlsInterface $recordCollectedUrls
    ) {
    }

    /**
     * Record the reported modules and answer whether the report was accepted.
     *
     * @return Json
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     * @throws \Zend_Cache_Exception When the URL list storage cannot be opened
     */
    public function execute(): Json
    {
        $token = $this->request->getParam('key');
        $base = $this->request->getParam('base');
        $urls = $this->stringList($this->request->getParam('list'));

        $result = is_string($token) && is_string($base) && $urls !== null
            && $this->recordCollectedUrls->execute($token, $base, $urls);

        return $this->jsonFactory->create()->setData(['result' => $result]);
    }

    /**
     * The value as a list of strings.
     *
     * @param mixed $value Request parameter
     * @return list<string>|null The list, or null when the value is not a list of strings
     */
    private function stringList(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $strings = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                return null;
            }
            $strings[] = $item;
        }

        return $strings;
    }
}
