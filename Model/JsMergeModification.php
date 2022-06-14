<?php
/**
 * Copyright (c) 2022. All rights reserved.
 * @author: Volodymyr Hryvinskyi <mailto:volodymyr@hryvinskyi.com>
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeedApi\Model\ModificationInterface;
use Hryvinskyi\PageSpeedJsMerge\Api\ConfigInterface;
use Hryvinskyi\PageSpeedJsMerge\Api\MergeJsInterface;

class JsMergeModification implements ModificationInterface
{
    private ConfigInterface $config;
    private MergeJsInterface $mergeJs;

    /**
     * @param ConfigInterface $config
     * @param MergeJsInterface $mergeJs
     */
    public function __construct(ConfigInterface $config, MergeJsInterface $mergeJs)
    {
        $this->config = $config;
        $this->mergeJs = $mergeJs;
    }

    /**
     * @inheritDoc
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function execute(string &$html): void
    {
        if ($this->config->isMergeJsEnabled()) {
            $this->mergeJs->merge($html);
        }

        if ($this->config->isMergeInlineJsEnabled()) {
            $this->mergeJs->inline($html);
        }
    }
}
