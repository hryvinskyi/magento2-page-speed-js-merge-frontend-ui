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
use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\MergeProcessorPoolInterface;

/**
 * Pool for managing multiple merge processors
 *
 * This implementation allows multiple processors to be registered
 * and executed in chain based on sort order
 */
class MergeProcessorPool implements MergeProcessorPoolInterface
{
    /**
     * @var array<string, array{processor: MergeProcessorInterface, sortOrder: int}>
     */
    private readonly array $processors;

    /**
     * @var array<string, MergeProcessorInterface>|null
     */
    private ?array $sortedProcessors = null;

    /**
     * @param array<string, array{processor: MergeProcessorInterface, sortOrder: int}> $processors
     */
    public function __construct(array $processors = [])
    {
        $this->processors = $processors;
    }

    /**
     * @inheritDoc
     */
    public function processContent(ProcessContentDataInterface $data): string
    {
        foreach ($this->getProcessors() as $processor) {
            $processedContent = $processor->processContent($data);
            $data->setContent($processedContent);
        }

        return $data->getContent();
    }

    /**
     * @inheritDoc
     */
    public function processAttributes(array $attributes, string $mergedFilePath): array
    {
        $processedAttributes = $attributes;

        foreach ($this->getProcessors() as $processor) {
            $processedAttributes = $processor->processAttributes($processedAttributes, $mergedFilePath);
        }

        return $processedAttributes;
    }

    /**
     * @inheritDoc
     */
    public function getProcessors(): array
    {
        if ($this->sortedProcessors === null) {
            $this->sortedProcessors = $this->sortProcessors();
        }

        return $this->sortedProcessors;
    }

    /**
     * Sort processors by sort order
     *
     * @return array<string, MergeProcessorInterface>
     */
    private function sortProcessors(): array
    {
        $sorted = $this->processors;

        uasort($sorted, function ($a, $b) {
            return ($a['sortOrder'] ?? 0) <=> ($b['sortOrder'] ?? 0);
        });

        $result = [];
        foreach ($sorted as $name => $config) {
            $result[$name] = $config['processor'];
        }

        return $result;
    }
}