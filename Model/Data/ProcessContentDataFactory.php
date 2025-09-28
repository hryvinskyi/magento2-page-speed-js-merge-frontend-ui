<?php
/**
 * Copyright (c) 2025. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Model\Data;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\Data\ProcessContentDataInterface;
use Magento\Framework\ObjectManagerInterface;

/**
 * Factory for ProcessContentData objects
 *
 * Following Magento 2 factory pattern for data objects
 */
class ProcessContentDataFactory
{
    private readonly ObjectManagerInterface $objectManager;

    /**
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create new ProcessContentData instance
     *
     * @param array<string, mixed> $data Initial data
     * @return ProcessContentDataInterface
     */
    public function create(array $data = []): ProcessContentDataInterface
    {
        /** @var ProcessContentDataInterface $instance */
        $instance = $this->objectManager->create(ProcessContentDataInterface::class);

        if (!empty($data['content'])) {
            $instance->setContent((string)$data['content']);
        }
        if (!empty($data['filePath'])) {
            $instance->setFilePath((string)$data['filePath']);
        }
        if (!empty($data['mergedFilePath'])) {
            $instance->setMergedFilePath((string)$data['mergedFilePath']);
        }
        if (isset($data['isFirstFile'])) {
            $instance->setIsFirstFile((bool)$data['isFirstFile']);
        }
        if (isset($data['isLastFile'])) {
            $instance->setIsLastFile((bool)$data['isLastFile']);
        }
        if (isset($data['currentFileIndex'])) {
            $instance->setCurrentFileIndex((int)$data['currentFileIndex']);
        }
        if (isset($data['totalFiles'])) {
            $instance->setTotalFiles((int)$data['totalFiles']);
        }

        return $instance;
    }
}