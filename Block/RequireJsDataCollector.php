<?php
/**
 * Copyright (c) 2022. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Block;

use Hryvinskyi\PageSpeedJsMerge\Api\ConfigInterface;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Magento\Framework\View\Element\Template;

class RequireJsDataCollector extends Template
{
    private ConfigInterface $config;
    private RequireJsManager $requireJsManager;

    /**
     * @param ConfigInterface $config
     * @param RequireJsManager $requireJsManager
     * @param Template\Context $context
     * @param array $data
     */
    public function __construct(
        ConfigInterface $config,
        RequireJsManager $requireJsManager,
        Template\Context $context,
        array $data = [])
    {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->requireJsManager = $requireJsManager;
    }

    /**
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->config->isMergeJsEnabled()) {
            return '';
        }

        return parent::_toHtml();
    }

    /**
     * @return string
     */
    public function getRequestUrl(): string
    {
        return $this->getUrl('pagespeedjsmerge/js/collect');
    }

    /**
     * @return string
     */
    public function getScriptDataKey(): string
    {
        return RequireJsManager::SCRIPT_TAG_DATA_KEY;
    }

    /**
     * @return string
     */
    public function getPageCacheTags(): string
    {
        return RequireJsManager::TAG_VALUE_PLACEHOLDER;
    }

    /**
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getRouteKey(): string
    {
        return $this->requireJsManager->getRouteKeyByLayout($this->getLayout());
    }

    /**
     * @return string[]
     */
    public function getIgnore(): array
    {
        $result = [];
        $list = $this->config->getExcludeAnchors();
        foreach ($list as $value) {
            $result[] = base64_encode($value);
        }
        return $result;
    }
}
