<?php
/**
 * Copyright (c) 2025. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\Data\ProcessContentDataInterface;
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\MergeProcessorInterface;

/**
 * Default merge processor with no additional processing
 *
 * This is the default implementation that does minimal processing
 */
class DefaultMergeProcessor implements MergeProcessorInterface
{
    /**
     * @inheritDoc
     */
    public function processContent(ProcessContentDataInterface $data): string
    {
        return $data->getContent() . ';';
    }

    /**
     * @inheritDoc
     */
    public function processAttributes(array $attributes, string $mergedFilePath): array
    {
        return $attributes;
    }
}