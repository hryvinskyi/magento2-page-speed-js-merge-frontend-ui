<?php
/**
 * Copyright (c) 2025. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\PageSpeedJsMergeFrontendUi\Api;

use Hryvinskyi\PageSpeedJsMergeFrontendUi\Api\Data\ProcessContentDataInterface;

/**
 * Interface for managing multiple merge processors
 *
 * This pool allows multiple processors to be registered and executed
 * in a chain, enabling different modules to extend merge functionality
 */
interface MergeProcessorPoolInterface
{
    /**
     * Process content through all registered processors
     *
     * @param ProcessContentDataInterface $data All data needed for processing
     * @return string Processed content
     */
    public function processContent(ProcessContentDataInterface $data): string;

    /**
     * Process merged file attributes through all registered processors
     *
     * @param array<string, string> $attributes Current attributes for the merged script tag
     * @param string $mergedFilePath Path to the merged file
     * @return array<string, string> Updated attributes
     */
    public function processAttributes(array $attributes, string $mergedFilePath): array;

    /**
     * Get all registered processors sorted by sort order
     *
     * @return array<string, MergeProcessorInterface> Array of processors keyed by name
     */
    public function getProcessors(): array;
}