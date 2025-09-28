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
 * Interface for processing merged JavaScript files
 *
 * This extension point allows other modules to add functionality
 * like script markers, lazy loading, etc. to merged files
 */
interface MergeProcessorInterface
{
    /**
     * Process content before merging files
     *
     * @param ProcessContentDataInterface $data All data needed for processing
     * @return string Processed content
     */
    public function processContent(ProcessContentDataInterface $data): string;

    /**
     * Process merged file attributes
     *
     * @param array<string, string> $attributes Current attributes for the merged script tag
     * @param string $mergedFilePath Path to the merged file
     * @return array<string, string> Updated attributes
     */
    public function processAttributes(array $attributes, string $mergedFilePath): array;
}