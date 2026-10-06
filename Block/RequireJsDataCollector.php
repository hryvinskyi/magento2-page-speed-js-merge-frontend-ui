<?php
/**
 * Copyright (c) 2022-2026. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Block;

use Hryvinskyi\PageSpeedJsMerge\Api\ConfigInterface;
use Hryvinskyi\PageSpeedJsMerge\Model\RequireJsManager;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\CollectTokenInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Template;

/**
 * Prints the storefront collector that reports the RequireJS modules a page loaded beyond its merged bundle.
 *
 * The script tag carries the plain route key in its data attribute, which the server reads back from rendered HTML;
 * the collector itself reports under a collect token, the only key form the collect endpoint accepts.
 */
class RequireJsDataCollector extends Template
{
    /**
     * @param ConfigInterface $config JS merge settings
     * @param RequireJsManager $requireJsManager Builds the route key of the page
     * @param CollectTokenInterface $collectToken Signs the route key for the collector
     * @param JsonHexTag $jsonHexTag Encodes script arguments so that no "</script>" can end the script
     * @param Template\Context $context Block context
     * @param array<string, mixed> $data Block data
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly RequireJsManager $requireJsManager,
        private readonly CollectTokenInterface $collectToken,
        private readonly JsonHexTag $jsonHexTag,
        Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Render nothing while JS merging is disabled.
     *
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
     * URL of the collect endpoint.
     *
     * @return string
     */
    public function getRequestUrl(): string
    {
        return $this->getUrl('pagespeedjsmerge/js/collect');
    }

    /**
     * Name of the script tag attribute that carries the route key.
     *
     * @return string
     */
    public function getScriptDataKey(): string
    {
        return RequireJsManager::SCRIPT_TAG_DATA_KEY;
    }

    /**
     * Placeholder the page's cache tags replace after rendering.
     *
     * @return string
     * @deprecated The collector no longer reports cache tags; kept for templates that still print it.
     */
    public function getPageCacheTags(): string
    {
        return RequireJsManager::TAG_VALUE_PLACEHOLDER;
    }

    /**
     * Route key of the page, as the server reads it back from the rendered HTML.
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    public function getRouteKey(): string
    {
        return $this->requireJsManager->getRouteKeyByLayout($this->getLayout());
    }

    /**
     * Collect token the collector reports under.
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    public function getCollectKey(): string
    {
        return $this->collectToken->issue($this->getRouteKey());
    }

    /**
     * Exclusion anchors, base64-encoded for the collector.
     *
     * @return list<string>
     */
    public function getIgnore(): array
    {
        $result = [];
        foreach ($this->config->getExcludeAnchors() as $value) {
            if (is_string($value)) {
                $result[] = base64_encode($value);
            }
        }

        return $result;
    }

    /**
     * Arguments of the collector's init call, each encoded as hex-tagged JSON and separated by commas.
     *
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException When the current store cannot be resolved
     */
    public function getCollectorArguments(): string
    {
        return implode(',', [
            $this->jsonHexTag->serialize($this->getCollectKey()),
            $this->jsonHexTag->serialize($this->getRequestUrl()),
            // The collect endpoint ignores tags; an empty value keeps the page's tag list out of the page.
            $this->jsonHexTag->serialize(''),
            $this->jsonHexTag->serialize($this->getIgnore()),
        ]);
    }
}
