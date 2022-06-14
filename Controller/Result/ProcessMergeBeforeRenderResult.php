<?php
/**
 * Copyright (c) 2022. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Controller\Result;

use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Magento\Framework\App\Response\Http;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\View\Result\Layout;

class ProcessMergeBeforeRenderResult
{
    private RequireJsManager $requireJsManager;

    /**
     * @param RequireJsManager $requireJsManager
     */
    public function __construct(RequireJsManager $requireJsManager)
    {
        $this->requireJsManager = $requireJsManager;
    }

    /**
     * @param Layout $subject
     * @param $result
     * @param ResponseInterface $httpResponse
     * @return mixed
     * @throws \Exception
     * @noinspection PhpUnusedParameterInspection
     */
    public function afterRenderResult(Layout $subject, $result, ResponseInterface $httpResponse)
    {
        if ($httpResponse instanceof Http) {
            $this->requireJsManager->processWithResponseHttp($httpResponse);
        }

        return $result;
    }
}
